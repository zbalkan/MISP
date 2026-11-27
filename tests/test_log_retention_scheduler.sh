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
    query "DELETE FROM access_logs WHERE request_id IN ('$marker-old', '$marker-recent')" || exit 1
    query "DELETE FROM scheduled_tasks WHERE $task_where" || exit 1
    query "DELETE FROM jobs WHERE $job_where" || exit 1
    query "DELETE FROM admin_settings WHERE setting = 'log_retention_checkpoint_access_logs'" || exit 1
}
trap cleanup EXIT

cake Admin setSetting MISP.audit_log_retention_days 0
cake Admin setSetting MISP.log_retention_days 0
cake Admin setSetting MISP.access_log_retention_days 0

query "INSERT INTO access_logs
    (created, user_id, org_id, request_method, request_id, controller, action, url, response_code, memory_usage, duration, query_count)
    VALUES ('2000-01-01 00:00:00', 0, 0, 0, '$marker-old', 'retention_test', 'index', '/retention-test', 200, 0, 0, 0),
           (UTC_TIMESTAMP(4), 0, 0, 0, '$marker-recent', 'retention_test', 'index', '/retention-test', 200, 0, 0, 0)"

# The real CLI must be non-destructive in dry-run mode.
cake Log accessLogRetention 30 --dry-run
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id IN ('$marker-old', '$marker-recent')")" = 2

# Verify the actual Cake process exit status, not only shell method returns.
cake Log retention --dry-run
if MISP_SETTING_MISP__audit_log_retention_days=invalid cake Log retention --dry-run; then
    echo 'Blocked configured retention unexpectedly returned success.' >&2
    exit 1
fi
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id IN ('$marker-old', '$marker-recent')")" = 2

# The running scheduler must observe the fresh configuration, provision the
# native task, claim it, enqueue a real job and execute the configured sweep.
cake Admin setSetting MISP.access_log_retention_days 30
deadline=$((SECONDS + 180))
while (( SECONDS < deadline )); do
    state=$(query "SELECT j.status FROM scheduled_tasks t JOIN jobs j ON j.id = t.last_job_id WHERE t.type = 'Admin' AND t.action = 'enforceLogRetention'")
    case "$state" in
        4) break ;;
        3) echo 'Retention worker reported failure.' >&2; exit 1 ;;
    esac
    sleep 2
done

test "${state:-}" = 4
test "$(query "SELECT COUNT(*) FROM scheduled_tasks WHERE $task_where AND enabled = 1 AND timer = 86400 AND user_id = 0 AND next_execution_time > UNIX_TIMESTAMP()")" = 1
test "$(query "SELECT COUNT(*) FROM jobs WHERE $job_where")" = 1
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-old'")" = 0
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-recent'")" = 1
test "$(query "SELECT COUNT(*) FROM admin_settings WHERE setting = 'log_retention_checkpoint_access_logs'")" = 1
echo 'Native scheduler, job association, worker, checkpoint and retention cutoff verified.'

# The live application must protect the managed task and its dispatch guard,
# while allowing an older completed retention job to be removed normally.
task_id=$(query "SELECT id FROM scheduled_tasks WHERE $task_where")
job_id=$(query "SELECT last_job_id FROM scheduled_tasks WHERE $task_where")
test -n "$task_id"
test -n "$job_id"
http_status=$(curl -sS -o /dev/null -w '%{http_code}' -H "Authorization: $AUTH" -H 'Accept: application/json' -H 'Content-Type: application/json' -X POST "http://$HOST/tasks/forceRun/$task_id")
test "$http_status" = 405
query "INSERT INTO jobs (worker, job_type, job_input, status, message, progress, org_id, date_created, date_modified)
    VALUES ('default', 'admin_action', 'enforceLogRetention', 4, 'retention CI historical job', 100, 0, NOW(), NOW())"
historical_id=$(query "SELECT id FROM jobs WHERE $job_where AND message = 'retention CI historical job'")
test -n "$historical_id"
curl -sS --fail-with-body -o /dev/null -H "Authorization: $AUTH" -H 'Accept: application/json' -H 'Content-Type: application/json' --data "{\"Job\":{\"id\":[$job_id,$historical_id]}}" "http://$HOST/jobs/deleteSelection"
test "$(query "SELECT COUNT(*) FROM jobs WHERE id = $job_id")" = 1
test "$(query "SELECT COUNT(*) FROM jobs WHERE id = $historical_id")" = 0
test "$(query "SELECT COUNT(*) FROM scheduled_tasks WHERE id = $task_id AND last_job_id = $job_id")" = 1
echo 'Managed task and current job protected through the live administration API.'

# Exercise the next native schedule without waiting a day. Disable the policy
# before making the task due; the running scheduler must leave it untouched.
cake Admin setSetting MISP.access_log_retention_days 0
query "INSERT INTO access_logs
    (created, user_id, org_id, request_method, request_id, controller, action, url, response_code, memory_usage, duration, query_count)
    VALUES ('2000-01-01 00:00:00', 0, 0, 0, '$marker-second-old', 'retention_test', 'index', '/retention-test', 200, 0, 0, 0)"
query "UPDATE scheduled_tasks SET next_execution_time = UNIX_TIMESTAMP() - 1 WHERE id = $task_id AND last_job_id = $job_id"
# Observe more than two scheduler polling intervals.
sleep 25
test "$(query "SELECT COUNT(*) FROM scheduled_tasks WHERE id = $task_id AND last_job_id = $job_id AND next_execution_time <= UNIX_TIMESTAMP()")" = 1
test "$(query "SELECT COUNT(*) FROM jobs WHERE $job_where")" = 1
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-second-old'")" = 1

cake Admin setSetting MISP.access_log_retention_days 30
deadline=$((SECONDS + 180))
state=''
while (( SECONDS < deadline )); do
    state=$(query "SELECT j.status FROM scheduled_tasks t JOIN jobs j ON j.id = t.last_job_id WHERE t.id = $task_id AND j.id != $job_id")
    case "$state" in
        4) break ;;
        3) echo 'Second retention execution failed.' >&2; exit 1 ;;
    esac
    sleep 2
done
test "$state" = 4
test "$(query "SELECT COUNT(*) FROM scheduled_tasks WHERE $task_where")" = 1
test "$(query "SELECT COUNT(*) FROM scheduled_tasks WHERE id = $task_id AND last_job_id != $job_id AND next_execution_time > UNIX_TIMESTAMP()")" = 1
test "$(query "SELECT COUNT(*) FROM jobs WHERE $job_where")" = 2
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-second-old'")" = 0
test "$(query "SELECT COUNT(*) FROM access_logs WHERE request_id = '$marker-recent'")" = 1
echo 'Disabled policy, managed task reuse and subsequent native execution verified.'
