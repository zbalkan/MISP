# Database log retention

MISP can automatically delete expired records from its three database-backed log tables. All policies are disabled by default, including on upgrades. This feature does not control event or attribute retention, the retention taxonomy, syslog, file logs, external logging systems or database backups.

**Deletion is irreversible.** Before enabling a policy, review legal, regulatory, contractual, audit and incident investigation requirements, and verify that a suitable backup or independent log archive can be restored. Historical event changes, login trust information, statistics and update recovery information may depend on these records. No record-level legal hold or event-specific exemption is provided.

## Settings

As a site administrator, open the server settings and diagnostics page and select **Logging & audit**. The same settings can be managed with the existing Admin shell.

| Setting | Table | Default |
| --- | --- | --- |
| `MISP.audit_log_retention_days` | `audit_logs` | `0` |
| `MISP.access_log_retention_days` | `access_logs` | `0` |
| `MISP.log_retention_days` | `logs` | `0` |
| `MISP.log_retention_timezone` | Audit and application log writer timezone | `unconfigured` |

The three periods are independent whole numbers from 0 to 36500. Zero disables the corresponding automatic policy. Negative, fractional and malformed values are rejected, including forced setting changes. Saving a positive period enables automatic deletion, which may begin shortly afterwards. Missing settings use the disabled or unconfigured defaults; explicitly saving null is invalid. The settings interface requires a valid writer timezone before enabling audit or application retention, and both periods must be disabled before clearing it. Runtime validation also handles direct file or environment changes and concurrent edits.

Access log timestamps are stored in UTC. Audit and application logs use timezone-naive timestamps written in the application timezone. Establish the actual historical writer timezone before enabling either policy. Set an IANA timezone such as `UTC` only if that is the timezone used by the writers. The setting does not change PHP's timezone or convert stored timestamps. If historical writers used different timezones, leave those policies disabled until the data has been assessed. Ambiguous repeated local times during a backward daylight-saving transition are handled conservatively.

Retention reads current configuration rather than relying on a worker's startup snapshot. Environment overrides have precedence over database-backed settings (when enabled), which have precedence over the configuration file. Use consistent retention environment variables in the scheduler and all default workers. Changing a service's environment requires restarting the affected processes; changing an environment variable in an interactive shell does not change an already running worker.

## Preview and enable

Run commands from the MISP installation directory as the installation's MISP service account (for example, `www-data` on Debian/Ubuntu or `apache` on RHEL). Substitute the actual account and path.

Preview a proposed policy without saving or enabling it:

```sh
app/Console/cake Log auditLogRetention 365 --log-timezone UTC --dry-run
app/Console/cake Log accessLogRetention 90 --dry-run
app/Console/cake Log applicationLogRetention 365 --log-timezone UTC --dry-run
```

The timezone in these examples is illustrative. A configured writer timezone takes precedence, and a conflicting command-line timezone is rejected. The existing access-log command retains its historical relative-date duration parser; configured access-log policies calculate calendar days in UTC. In a non-UTC CLI environment the two cutoffs may differ around daylight-saving transitions. Always inspect the reported cutoff.

After reviewing the results and obtaining the necessary approval, enable only the intended policies. For example, for a verified UTC writer and a 365-day audit policy:

```sh
app/Console/cake Admin setSetting MISP.log_retention_timezone UTC
app/Console/cake Admin setSetting MISP.audit_log_retention_days 365
```

To preview or run all configured policies manually:

```sh
app/Console/cake Log retention --dry-run
app/Console/cake Log retention
```

The second command deletes data. The three category-specific commands without `--dry-run` perform one-time deletion independently of the saved periods, including when automatic retention is disabled. Do not use them as a substitute for a reviewed policy. One-time commands traverse the full captured ID range and do not use the configured sweep's runtime limit or checkpoint.

## Automatic execution

Automatic retention uses the existing MISP scheduler and default background-job queue. Configure the native scheduler and default workers as described in the [background jobs migration guide](background-jobs-migration-guide.md). Ensure the selected backend and its Redis connection are operational.

When any period is positive, the scheduler provisions a single managed Admin task, `enforceLogRetention`. Its interval is 86400 seconds. The first execution can be due immediately after provisioning. Subsequent dispatches use the native task's next execution time and last job association. The managed task is not editable, deletable, toggleable or force-runnable through generic task administration. Use the retention settings to control it.

With the scheduler stopped, no new automatic jobs are dispatched. With default workers stopped, a previously queued job can remain waiting. When workers resume, configured retention rechecks the current settings before deletion. Setting all three periods to zero prevents new automatic dispatches and makes queued configured runs non-destructive; an existing task row can remain visible.

## Deletion and performance

Each sweep fixes a cutoff and high-water primary key. Only records with `created` strictly earlier than the cutoff are eligible; equality is retained. Bounded primary-key ranges handle sparse IDs and timestamps that are not ordered by ID. Each deletion batch is committed independently. New rows beyond the captured high-water mark are considered by a later sweep.

The default range is 10000 IDs, with a 200 ms delay between batches. Configured sweeps have a 30-minute cooperative runtime budget per table, not per job. A database statement or lock wait can extend this time. Progress is saved after committed batches in internal `admin_settings` records. An interrupted sweep can resume from a compatible checkpoint; incompatible or completed checkpoints are not reused. A policy change or backward clock change stops a configured sweep at a checked batch boundary. A change cannot roll back a batch already executing or committed.

Dry runs use the same cutoff, range traversal and exclusive table-scoped advisory lock without deleting records or changing checkpoints. A partial dry run reports only the eligible rows examined, not a whole-table estimate. Concurrent inserts and other application changes mean a dry-run count is not a guarantee of a later deletion count.

Retention requires a single writable database server, non-persistent sessions and transactional tables. MariaDB/MySQL log and checkpoint tables must use InnoDB. Known Galera/wsrep and MySQL Group Replication configurations are rejected. PostgreSQL retention is tested separately, but that does not establish complete MISP PostgreSQL deployment support. Advisory locks require a stable database backend session throughout each sweep. Use direct connections or session pooling; PgBouncer transaction and statement pooling are unsupported. The adapter cannot reliably detect every incompatible pooling configuration. Do not use this feature across independently writable servers, or fail over while a retention operation is active.

Deleting InnoDB records makes space available for reuse but does not necessarily reduce the tablespace file size. Retention does not run `OPTIMIZE TABLE` or rebuild tables automatically. Evaluate any separate space-reclamation operation against available disk space, locking, replication and maintenance-window requirements.

## Monitoring and disabling

Inspect the managed task in scheduled task administration and its associated job in the Jobs view. CLI output and job output report category, status, cutoff, batch count, affected rows and duration. `complete` and `disabled` are successful configured outcomes; `partial` and `busy` indicate incomplete work. A category with an invalid policy or writer timezone reports `blocked` without preventing independent valid policies from running. The combined job reports incomplete. A database or cleanup exception stops the combined run rather than reuse an uncertain database session. Review worker logs and database errors when a job fails. Previously committed batches remain deleted.

Disable automatic deletion with:

```sh
app/Console/cake Admin setSetting MISP.audit_log_retention_days 0
app/Console/cake Admin setSetting MISP.access_log_retention_days 0
app/Console/cake Admin setSetting MISP.log_retention_days 0
```

If an environment override exists, change it at its source and restart the affected services instead. Confirm the effective configuration. Allow in-flight batches to finish, and verify that no manual one-time command or external cleanup process remains active. Disabling retention does not restore deleted records.

## Recovering a blocked managed task

The task deliberately refuses a new dispatch if its last job is nonterminal or missing. A crash, manual database change or uncertain Redis enqueue outcome can leave such an association. A missing Redis status alone does not prove a job is absent: Redis job history expires and a queue write can succeed before a database process ID is recorded. Native job purges protect the associated job and nonterminal system retention jobs; older terminal jobs remain purgeable.

First disable all automatic retention policies at their effective sources. Inspect the task and job records, the default queue, worker processes and logs. For an unprefixed database the following read-only query identifies the association:

```sql
SELECT t.id, t.enabled, t.next_execution_time, t.last_job_id,
       t.message, j.status, j.process_id, j.progress, j.message AS job_message
FROM scheduled_tasks t
LEFT JOIN jobs j ON j.id = t.last_job_id
WHERE t.type = 'Admin' AND t.action = 'enforceLogRetention';
```

Job status 0 is newly created, 1 waiting, 2 running, 3 failed and 4 completed. If the job is still queued or running, allow it to finish with retention disabled. Do not clear the entire default queue or delete the associated job to unblock retention.

If the association remains unresolved, arrange a maintenance window. Stop all schedulers, default workers and any manual retention processes across the deployment. Confirm no associated command is running or queued for later execution, including jobs with no recorded process ID. If this cannot be established, keep retention disabled and investigate further.

Only after establishing quiescence and backing up the affected administrative state should a database administrator repair the exact stale association. For an existing abandoned job, mark that specific job failed using the normal job status fields; for a genuinely missing job, clear only the matching managed task's `last_job_id`. Record the original task ID, job ID, status and reason. Do not modify unrelated jobs, the task interval or checkpoint values. There is no automatic timeout-based claim reset. If multiple managed tasks exist, investigate and resolve the duplicates while all schedulers and workers remain stopped.

Restart the services with all policies disabled, verify the repaired state, preview the desired policies, and re-enable only after review. The next eligible native schedule will dispatch again. Use a configured manual run if immediate processing is required after recovery.

## Migrating from external cleanup

Inventory cron jobs, database events and scripts that delete these tables. Disable the old deletion mechanisms and wait for their transactions to finish before enabling native retention. External SQL does not participate in the native advisory locks. Do not run competing deletion policies. The existing `tools/misp_retention.py` concerns event/attribute retention taxonomy processing and is unrelated to database log retention.
