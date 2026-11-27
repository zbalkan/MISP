# Retention benchmark evidence

Experimental implementation and PHP keyset benchmark are preserved in `archive/log-retention-atomic-experiment` at `7544d43`. The initial candidate retains the existing idempotent executor and v1 checkpoints; atomic accounting is deferred, not rejected.

MariaDB 10.11 / PHP 8.3, [CI job](https://github.com/zbalkan/MISP/actions/runs/46066978275/job/140734551961): 200 lock checks 18.68 ms; 200 file policy reads 2.19 ms; 200 database-enabled policy reads 31.83 ms. Retain checks relative to 200 ms inter-batch delay.

| Checkpoint interval | Writes before interruption | Recovery batches | Total measured ms |
|---|---:|---:|---:|
| 1 | 13 | 7 | 20.65 |
| 5 | 2 | 10 | 15.48 |
| None | 0 | 20 | 25.97 |

| ID stride | Range batches / ms | PHP keyset batches / ms |
|---|---|---|
| 1 | 2 / 26.02 | 2 / 38.07 |
| 20 | 40 / 63.34 | 2 / 51.19 |

20,000 rows, 50% expired for keyset comparison. Timings exclude throttle; nominal sparse delay 7800 vs 200 ms. Synthetic single-run CI measurements do not establish production performance. No PostgreSQL benchmark comparison. Keep per-batch checkpoints; evaluate database-native selection separately. Opt-in benchmarks use PHPUnit `--group benchmark` with the same bootstrap and database environment as retention CI.
