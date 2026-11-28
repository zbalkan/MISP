#!/usr/bin/env bash
# Integration test for the disposable database and workers of the upstream CI job.
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-}" != true || "${MISP_RETENTION_CI:-}" != 1 ]]; then
    echo 'This test is restricted to the disposable GitHub Actions MISP instance.' >&2
    exit 1
fi

db=(mysql -h 127.0.0.1 --port 3306 -u misp -pblah -N -B misp)
query() { "${db[@]}" -e "$1"; }
cake() { app/Console/cake "$@"; }
task_where="type = 'Admin' AND action = 'enforceLogRetention'"
job_where="job_type = 'admin_action' AND job_input = 'enforceLogRetention'"
marker="retention-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"

# Require an unused retention schedule; never alter an existing task.
test "$(query "SELECT COUNT(*) FROM scheduled_tasks WHERE $task_where")" = 0
test "$(query "SELECT COUNT(*) FROM jobs WHERE $job_where")" = 0

cleanup() {
    local status=$?
    trap - EXIT
    set +e
    cake Admin setSetting MISP.access_log_retention_days 0
    local disabled=$?
    query "SELECT id, enabled, next_execution_time, last_job_id, message FROM scheduled_tasks WHERE $task_where"
    query "SELECT id, status, progress, message FROM jobs WHERE $job_where"
    if [[ "$status" != 0 || "$disabled" != 0 ]]; then
        tail -n 100 /tmp/misp-workers-errors.log /tmp/misp-workers.log 2>/dev/null || true
        exit 1
    fi
    query "DELETE FROM access_logs WHERE request_id IN ('$marker-old', '$marker-recent', '$marker-second-old')" || exit 1
    query "DELETE FROM scheduled_tasks WHERE $task_where" || exit 1
    query "DELETE FROM jobs WHERE $job_where" || exit 1
}
trap cleanup EXIT

cake Admin setSetting MISP.audit_log_retention_days 0
cake Admin setSetting MISP.log_retention_days 0
cake Admin setSetting MISP.access_log_retention_days 0

# Validate the live settings controller, including forced saves. Keep all
# policies disabled throughout these negative tests to avoid dispatch races.
setting_request() {
    curl -sS -H "Authorization: $AUTH" -H 'Accept: application/json' -H 'Content-Type: application/json' "$@"
}
setting_url="http://$HOST/servers/serverSettingsEdit"
setting_request --data '{"value":"unconfigured"}' "$setting_url/MISP.log_retention_timezone" | grep -F 'Field updated'
for setting in audit_log_retention_days access_log_retention_days log_retention_days; do
    for value in -1 36501 1.5 invalid; do
        setting_request --data "{\"value\":\"$value\",\"force\":true}" "$setting_url/MISP.$setting" | grep -F 'Retention must be an integer between 0 and 36500 days.'
    done
    setting_request "$setting_url/MISP.$setting" | python3 -c 'import json,sys; assert int(json.load(sys.stdin)[sys.argv[1]]) == 0' "MISP.$setting"
done
for setting in audit_log_retention_days log_retention_days; do
    setting_request --data '{"value":30,"force":true}' "$setting_url/MISP.$setting" | grep -F 'Configure a valid log writer timezone'
    setting_request "$setting_url/MISP.$setting" | python3 -c 'import json,sys; assert int(json.load(sys.stdin)[sys.argv[1]]) == 0' "MISP.$setting"
done
setting_request --data '{"value":"Invalid/Timezone","force":true}' "$setting_url/MISP.log_retention_timezone" | grep -F 'Specify a valid IANA log writer timezone'
setting_request "$setting_url/MISP.log_retention_timezone" | python3 -c 'import json,sys; assert json.load(sys.stdin)["MISP.log_retention_timezone"] == "unconfigured"'
echo 'Live settings controller rejects invalid and unsafe forced retention changes.'

query "INSERT INTO access_logs
    (created, user_id, org_id, request_method, request_id, controller, action, url, response_code, memory_usage, duration, query_count)
    VALUES ('2000-01-01 00:00:00', 0, 0, 0, '$marker-old', 'retention_test', 'index', '/retention-test', 200, 0, 0, 0),
           (UTC_TIMESTAMP(4), 0, 0, 0, '$marker-recent', 'retention_test', 'index', '/retention-test', 200, 0, 0, 0)"

# The real CLI must be non-destructive in dry-run mode.
cake Log accessLogRetention 30 --dry-run
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id IN ('$marker-old', '$marker-recent')")" = 2

# Verify the actual Cake process exit status, not only shell method returns.
output=$(cake Log retention --dry-run 2>&1)
for category in audit_logs access_logs logs; do
    grep -F "$category: mode=dry-run status=disabled" <<< "$output"
done
if output=$(MISP_SETTING_MISP__audit_log_retention_days=invalid cake Log retention --dry-run 2>&1); then
    echo 'Blocked configured retention unexpectedly returned success.' >&2
    exit 1
fi
grep -F 'audit_logs: mode=dry-run status=blocked' <<< "$output"
grep -F 'access_logs: mode=dry-run status=disabled' <<< "$output"
grep -F 'logs: mode=dry-run status=disabled' <<< "$output"

# Environment-only activation avoids waking the native scheduler. Hold the
# production advisory lock in a separate session for the CLI contention test.
lock_name=$(query "SELECT CONCAT('misp:retention:', LEFT(SHA2(CONCAT(DATABASE(), ':access_logs'), 256), 48))")
"${db[@]}" -e "SELECT GET_LOCK('$lock_name', 0); SELECT SLEEP(60)" >/dev/null &
lock_client=$!
deadline=$((SECONDS + 10))
while [[ "$(query "SELECT IS_USED_LOCK('$lock_name') IS NOT NULL")" != 1 ]]; do
    if (( SECONDS >= deadline )); then
        kill "$lock_client" 2>/dev/null || true
        echo 'Could not establish advisory lock contention.' >&2
        exit 1
    fi
    sleep 1
done
busy_exit=0
output=$(MISP_SETTING_MISP__access_log_retention_days=30 cake Log retention --dry-run 2>&1) || busy_exit=$?
# Kill the server-side connection, not just the client sleeping in SQL.
lock_owner=$(query "SELECT IS_USED_LOCK('$lock_name')")
if [[ "$lock_owner" =~ ^[0-9]+$ ]]; then
    query "KILL CONNECTION $lock_owner"
fi
wait "$lock_client" || true
test "$busy_exit" != 0
grep -F 'access_logs: mode=dry-run status=busy' <<< "$output"
output=$(MISP_SETTING_MISP__access_log_retention_days=30 cake Log retention --dry-run 2>&1)
grep -F 'access_logs: mode=dry-run status=complete' <<< "$output"
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id IN ('$marker-old', '$marker-recent')")" = 2

# Interrupt a real configured CLI process while its DELETE waits for a row
# lock. The persistent policy remains disabled, so no scheduler job competes.
interrupt_lock="$marker-interrupt"
"${db[@]}" -e "START TRANSACTION; SELECT id FROM access_logs WHERE request_id = '$marker-old' FOR UPDATE; SELECT GET_LOCK('$interrupt_lock', 0); SELECT SLEEP(60)" >/dev/null &
blocker_client=$!
deadline=$((SECONDS + 10))
while [[ "$(query "SELECT IS_USED_LOCK('$interrupt_lock') IS NOT NULL")" != 1 ]]; do
    if (( SECONDS >= deadline )); then
        kill "$blocker_client" 2>/dev/null || true
        echo 'Could not establish row-lock fixture.' >&2
        exit 1
    fi
    sleep 1
done
blocker_id=$(query "SELECT IS_USED_LOCK('$interrupt_lock')")
MISP_SETTING_MISP__access_log_retention_days=30 app/Console/cake Log retention >/tmp/retention-interrupted.log 2>&1 &
retention_pid=$!
deadline=$((SECONDS + 20))
waiting=0
while (( SECONDS < deadline )); do
    waiting=$(query "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = IS_USED_LOCK('$lock_name') AND INFO LIKE 'DELETE%access_logs%'")
    [[ "$waiting" = 1 ]] && break
    kill -0 "$retention_pid" 2>/dev/null || break
    sleep 1
done
# The cake launcher execs PHP, so this PID is the actual CLI process.
kill -KILL "$retention_pid" 2>/dev/null || true
interrupted_exit=0
wait "$retention_pid" || interrupted_exit=$?
query "KILL CONNECTION $blocker_id"
wait "$blocker_client" || true
test "$waiting" = 1
test "$interrupted_exit" = 137
deadline=$((SECONDS + 10))
while [[ "$(query "SELECT IS_USED_LOCK('$lock_name') IS NOT NULL")" != 0 ]]; do
    if (( SECONDS >= deadline )); then
        echo 'Interrupted CLI session did not release its lock.' >&2
        exit 1
    fi
    sleep 1
done
# The server may finish an in-flight statement after client death, but
# without a subsequent COMMIT its transaction must be rolled back.
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id IN ('$marker-old', '$marker-recent')")" = 2
echo 'Abrupt CLI process interruption rolled back deletion and released the advisory lock.'

# Create an ordinary native administrative task through the live controller.
admin_id=$(query "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.perm_site_admin = 1 AND u.disabled = 0 ORDER BY u.id LIMIT 1")
test -n "$admin_id"
cake Admin setSetting MISP.access_log_retention_days 30
curl -sS --fail-with-body -H "Authorization: $AUTH" -H 'Accept: application/json' -H 'Content-Type: application/json' --data "{\"Task\":{\"type\":\"Admin\",\"admin_action\":\"enforceLogRetention\",\"user_id\":$admin_id,\"enabled\":1,\"time_multiplier\":1,\"time_unit\":86400,\"next_execution_date\":\"\",\"next_execution_time\":\"\",\"description\":\"Retention CI\"}}" "http://$HOST/tasks/add" >/dev/null
task_id=$(query "SELECT id FROM scheduled_tasks WHERE $task_where AND user_id = $admin_id AND enabled = 1 AND timer = 86400")
test -n "$task_id"

wait_for_job() {
    local previous=${1:-0} deadline=$((SECONDS + 180)) state=''
    while (( SECONDS < deadline )); do
        state=$(query "SELECT j.status FROM scheduled_tasks t JOIN jobs j ON j.id = t.last_job_id WHERE t.id = $task_id AND j.id != $previous")
        case "$state" in
            4) return 0 ;;
            3) echo 'Retention worker reported failure.' >&2; return 1 ;;
        esac
        sleep 2
    done
    echo 'Timed out waiting for native retention job.' >&2
    return 1
}

wait_for_job
job_id=$(query "SELECT last_job_id FROM scheduled_tasks WHERE id = $task_id")
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-old'")" = 0
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-recent'")" = 1

# A disabled retention policy is a successful no-op, not a disabled schedule.
cake Admin setSetting MISP.access_log_retention_days 0
query "INSERT INTO access_logs (created, user_id, org_id, request_method, request_id, controller, action, url, response_code, memory_usage, duration, query_count) VALUES ('2000-01-01', 0, 0, 0, '$marker-second-old', 'retention_test', 'index', '/retention-test', 200, 0, 0, 0)"
query "UPDATE scheduled_tasks SET next_execution_time = UNIX_TIMESTAMP() - 1 WHERE id = $task_id"
wait_for_job "$job_id"
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-second-old'")" = 1
job_id=$(query "SELECT last_job_id FROM scheduled_tasks WHERE id = $task_id")

cake Admin setSetting MISP.access_log_retention_days 30
query "UPDATE scheduled_tasks SET next_execution_time = UNIX_TIMESTAMP() - 1 WHERE id = $task_id"
wait_for_job "$job_id"
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-second-old'")" = 0
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-recent'")" = 1
test "$(query "SELECT COUNT(*) FROM scheduled_tasks WHERE id = $task_id AND next_execution_time > UNIX_TIMESTAMP()")" = 1
test "$(query "SELECT COUNT(*) FROM jobs WHERE $job_where")" = 3
echo 'Native administrative task, disabled policy and repeated retention execution verified.'
