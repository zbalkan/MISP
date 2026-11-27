<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionDatabase.php';
require_once __DIR__ . '/../Lib/Tools/LogRetentionRunner.php';

class LogRetentionDatabaseTest extends TestCase
{
    private $pdo;
    private $prefix;
    private $tables = [];

    protected function setUp(): void
    {
        if (getenv('MISP_RETENTION_TEST_DSN') === false) {
            $this->markTestSkipped('Set MISP_RETENTION_TEST_DSN to a disposable MariaDB database.');
        }
        $this->pdo = $this->connect();
        $this->prefix = 'retention_test_' . bin2hex(random_bytes(6)) . '_';
        foreach (['audit_logs', 'access_logs', 'logs'] as $table) {
            $precision = $table === 'access_logs' ? '(4)' : '';
            $this->createTable($table, "id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, created DATETIME{$precision} NOT NULL");
        }
        $this->createTable('admin_settings', 'setting VARCHAR(255) NOT NULL PRIMARY KEY, value TEXT NOT NULL');
        $this->createTable('system_settings', 'setting VARCHAR(255) NOT NULL PRIMARY KEY, value BLOB NOT NULL');
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            foreach ($this->tables as $table) {
                $this->pdo->exec("DROP TABLE IF EXISTS " . $this->table($table));
            }
        }
    }

    private function connect(): PDO
    {
        return new PDO(getenv('MISP_RETENTION_TEST_DSN'), getenv('MISP_RETENTION_TEST_USER') ?: '', getenv('MISP_RETENTION_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false,
        ]);
    }

    private function table(string $table): string
    {
        return chr(96) . $this->prefix . $table . chr(96);
    }

    private function createTable(string $table, string $columns): void
    {
        $this->pdo->exec("CREATE TABLE " . $this->table($table) . " ($columns) ENGINE=InnoDB");
        $this->tables[] = $table;
    }

    private function adapter(string $alias, string $table, ?PDO $pdo = null, ?DateTimeZone $timezone = null): LogRetentionDatabase
    {
        return new LogRetentionDatabase(new RetentionDatabaseTestModel($alias, $table, $pdo ?? $this->pdo, $this->prefix), $timezone ?? new DateTimeZone('UTC'));
    }

    private function seed(string $table): void
    {
        $this->pdo->exec("INSERT INTO " . $this->table($table) . " (id, created) VALUES
            (1, '2025-01-01 00:00:00'), (2, '2026-01-01 00:00:00'),
            (10000, '2025-12-31 23:59:59'), (30000, '2025-01-01 00:00:00'),
            (30001, '2026-01-02 00:00:00')");
    }

    private function ids(string $table): array
    {
        return array_map('intval', $this->pdo->query("SELECT id FROM " . $this->table($table) . " ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    }

    private function seedNativeRetentionTask(): void
    {
        class_alias(RetentionModelTestApp::class, 'App');
        class_alias(RetentionModelTestBase::class, 'AppModel');
        require_once __DIR__ . '/../Model/Task.php';
        require_once __DIR__ . '/../Model/Job.php';
        $this->createTable('scheduled_tasks', 'id INT PRIMARY KEY, type VARCHAR(100), action VARCHAR(40), user_id INT, enabled BOOLEAN, timer INT, next_execution_time INT, last_job_id INT NULL, message VARCHAR(255), last_run_at INT NULL');
        $this->createTable('jobs', "id INT PRIMARY KEY, status INT NOT NULL, job_type VARCHAR(32) NOT NULL DEFAULT 'admin_action', job_input TEXT NOT NULL, org_id INT NOT NULL DEFAULT 0");
        $table = $this->table('scheduled_tasks');
        $jobs = $this->table('jobs');
        $this->pdo->exec("INSERT INTO $table VALUES (1, 'Admin', 'enforceLogRetention', 0, TRUE, 86400, 100, NULL, '', NULL)");
    }

    private function nativeRetentionTask(PDO $pdo): Task
    {
        $task = new Task();
        $task->table = 'scheduled_tasks';
        $task->retentionDatasource = new RetentionDatabaseTestModel('Task', 'scheduled_tasks', $pdo, $this->prefix);
        return $task;
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNativeTaskClaimAndJobAssociationAcrossSessions(): void
    {
        $this->seedNativeRetentionTask();
        $table = $this->table('scheduled_tasks');
        $jobs = $this->table('jobs');
        $a = $this->nativeRetentionTask($this->pdo);
        $b = $this->nativeRetentionTask($this->connect());
        $snapshot = $this->pdo->query("SELECT * FROM $table")->fetch(PDO::FETCH_ASSOC);
        $claim = $a->claimRetentionExecution($snapshot, 100);
        $this->assertSame(86500, $claim);
        $this->assertNull($b->claimRetentionExecution($snapshot, 100));
        $this->assertFalse($a->attachRetentionJob(1, $claim, 7));
        $this->pdo->exec("INSERT INTO $jobs (id, status, job_input) VALUES (7, 0, 'enforceLogRetention')");
        $this->assertFalse($b->attachRetentionJob(1, 100, 7));
        $this->assertTrue($a->attachRetentionJob(1, $claim, 7));
        $this->assertFalse($b->attachRetentionJob(1, $claim, 7));
        $snapshot = $this->pdo->query("SELECT * FROM $table")->fetch(PDO::FETCH_ASSOC);
        foreach ([0, 1, 2] as $status) {
            $this->pdo->exec("UPDATE $jobs SET status = $status");
            $this->assertNull($b->claimRetentionExecution($snapshot, 86500));
        }
        $this->pdo->exec("UPDATE $jobs SET status = 4");
        $this->pdo->exec("INSERT INTO $jobs (id, status, job_input) VALUES (8, 4, 'enforceLogRetention'), (9, 0, 'other'), (10, 0, 'enforceLogRetention')");
        $jobModel = new Job();
        $jobModel->retentionDatasource = new RetentionDatabaseTestModel('Job', 'jobs', $this->pdo, $this->prefix);
        $condition = $jobModel->purgeableJobCondition();
        $this->assertSame([8, 9], array_map('intval', $this->pdo->query("SELECT id FROM $jobs WHERE $condition ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->pdo->exec("DELETE FROM $jobs WHERE $condition");
        $this->assertSame([7, 10], array_map('intval', $this->pdo->query("SELECT id FROM $jobs ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(172900, $b->claimRetentionExecution($snapshot, 86500));
        $this->assertNull($a->claimRetentionExecution($snapshot, 86500));
        // The former last terminal job is no longer protected.
        $this->assertSame([7], array_map('intval', $this->pdo->query("SELECT id FROM $jobs WHERE $condition")->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInterruptedRetentionDispatchRecovery(): void
    {
        $this->seedNativeRetentionTask();
        $table = $this->table('scheduled_tasks');
        $jobs = $this->table('jobs');
        $snapshot = function () use ($table) {
            return $this->pdo->query("SELECT * FROM $table")->fetch(PDO::FETCH_ASSOC);
        };
        $worker = $this->nativeRetentionTask($this->connect());
        $firstClaim = $worker->claimRetentionExecution($snapshot(), 100);
        $this->assertSame(86500, $firstClaim);
        // Simulate interruption after claim but before creating a job.
        unset($worker);
        $worker = $this->nativeRetentionTask($this->connect());
        $this->assertNull($worker->claimRetentionExecution($snapshot(), 86499));
        $secondClaim = $worker->claimRetentionExecution($snapshot(), 86500);
        $this->assertSame(172900, $secondClaim);
        $this->pdo->exec("INSERT INTO $jobs (id, status, job_input) VALUES (7, 0, 'enforceLogRetention')");
        $this->assertFalse($worker->attachRetentionJob(1, $firstClaim, 7));
        $this->assertTrue($worker->attachRetentionJob(1, $secondClaim, 7));
        // Simulate interruption after association, with unknown enqueue outcome.
        unset($worker);
        $worker = $this->nativeRetentionTask($this->connect());
        $this->assertNull($worker->claimRetentionExecution($snapshot(), 172900));
        $this->assertNull($worker->claimRetentionExecution($snapshot(), 259300));
        $this->pdo->exec("UPDATE $jobs SET status = " . Job::STATUS_RUNNING . " WHERE id = 7");
        $this->assertNull($worker->claimRetentionExecution($snapshot(), 259300));
        // A missing associated job must also fail closed.
        $this->pdo->exec("DELETE FROM $jobs WHERE id = 7");
        $this->assertNull($worker->claimRetentionExecution($snapshot(), 259300));
        // Simulate an administrator's verified, quiescent repair.
        $this->pdo->exec("UPDATE $table SET last_job_id = NULL WHERE id = 1 AND last_job_id = 7");
        $this->assertSame(345700, $worker->claimRetentionExecution($snapshot(), 259300));
        $this->assertNull($worker->claimRetentionExecution($snapshot(), 259300));
    }

    /** @group benchmark */
    public function testPolicyAndLockCheckBenchmark(): void
    {
        require_once __DIR__ . '/../Lib/Tools/LogRetentionPolicyReader.php';
        $config = tempnam(sys_get_temp_dir(), 'retention-benchmark-');
        $db = $this->adapter('AccessLog', 'access_logs');
        $this->assertTrue($db->acquireLock());
        try {
            foreach ([false, true] as $databaseSettings) {
                file_put_contents($config, '<?php $config = ' . var_export(['MISP' => [
                    'system_setting_db' => $databaseSettings,
                    'access_log_retention_days' => 30,
                ]], true) . ';');
                $checks = 200;
                $policyReads = 0;
                $databaseReads = 0;
                $read = function ($setting) use ($db, &$databaseReads) {
                    ++$databaseReads;
                    return $db->readPolicySetting($setting);
                };
                $start = hrtime(true);
                for ($i = 0; $i < $checks; ++$i) {
                    $this->assertTrue($db->ownsLock());
                }
                $lockMs = (hrtime(true) - $start) / 1e6;
                $start = hrtime(true);
                for ($i = 0; $i < $checks; ++$i) {
                    $this->assertSame(30, LogRetentionPolicyReader::read('access_log_retention_days', $config, $read));
                    ++$policyReads;
                }
                $policyMs = (hrtime(true) - $start) / 1e6;
                $this->assertSame($databaseSettings ? $checks : 0, $databaseReads);
                fwrite(STDOUT, json_encode([
                    'benchmark' => 'policy_and_lock_checks',
                    'database_settings' => $databaseSettings,
                    'lock_checks' => $checks,
                    'policy_reads' => $policyReads,
                    'database_policy_reads' => $databaseReads,
                    'lock_ms' => $lockMs,
                    'policy_ms' => $policyMs,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
            }
        } finally {
            $db->releaseLock();
            unlink($config);
        }
    }

    public function testConcurrentWriterAndHighWaterMark(): void
    {
        $table = $this->table('access_logs');
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $writer = $this->connect();
        foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
            $this->pdo->exec("DELETE FROM $table");
            $this->pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL $isolation");
            $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2000-01-01'), (20000, '2000-01-01')");
            $db = $this->adapter('AccessLog', 'access_logs');
            $this->assertTrue($db->acquireLock());
            try {
                $high = $db->highWaterMark();
                $this->assertSame(20000, $high);
                $this->assertSame(1, $db->processBatch(1, 10000, $cutoff, false));
                $start = hrtime(true);
                $writer->exec("INSERT INTO $table (id, created) VALUES (5000, '2000-01-01'), (20001, '2000-01-01')");
                $insertMs = (hrtime(true) - $start) / 1e6;
                $result = (new LogRetentionSweep())->run(10000, $high, $cutoff,
                    [$db, 'nextId'], [$db, 'processBatch'], static function () {}, [$db, 'ownsLock']);
                $this->assertTrue($result['complete']);
                $this->assertSame(1, $result['affected']);
                $this->assertSame([5000, 20001], $this->ids('access_logs'));
                $next = (new LogRetentionSweep())->run(0, $db->highWaterMark(), $cutoff,
                    [$db, 'nextId'], [$db, 'processBatch'], static function () {}, [$db, 'ownsLock']);
                $this->assertSame(2, $next['affected']);
                $this->assertSame([], $this->ids('access_logs'));
                fwrite(STDOUT, json_encode([
                    'benchmark' => 'writer_between_batches',
                    'isolation' => $isolation,
                    'insert_ms' => $insertMs,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
            } finally {
                $db->releaseLock();
            }
        }
    }

    public function testDeleteRangeGapLockCharacterisation(): void
    {
        $table = $this->table('access_logs');
        $writer = $this->connect();
        $writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
        foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
            $this->pdo->exec("DELETE FROM $table");
            $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2000-01-01'), (10000, '2000-01-01'), (20000, '2099-01-01')");
            $this->pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL $isolation");
            $this->pdo->beginTransaction();
            try {
                // Mirror the production DELETE while holding its transaction
                // open to observe contention from an independent session.
                $this->pdo->exec("DELETE FROM $table WHERE id >= 1 AND id <= 10000 AND created < '2026-01-01'");
                foreach ([5000, 30000] as $id) {
                    $start = hrtime(true);
                    $blocked = false;
                    try {
                        $writer->exec("INSERT INTO $table (id, created) VALUES ($id, '2099-01-01')");
                    } catch (PDOException $e) {
                        $this->assertSame(1205, (int)$e->errorInfo[1]);
                        $blocked = true;
                    }
                    fwrite(STDOUT, json_encode([
                        'benchmark' => 'delete_range_gap_lock',
                        'isolation' => $isolation,
                        'insert_id' => $id,
                        'blocked' => $blocked,
                        'elapsed_ms' => (hrtime(true) - $start) / 1e6,
                    ], JSON_THROW_ON_ERROR) . PHP_EOL);
                    if ($id === 30000 || $isolation === 'READ COMMITTED') {
                        $this->assertFalse($blocked);
                    }
                }
            } finally {
                $this->pdo->rollBack();
            }
        }
    }

    public function testEndOfIndexAppendContention(): void
    {
        $table = $this->table('access_logs');
        $writer = $this->connect();
        $writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
        foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
            $this->pdo->exec("DELETE FROM $table");
            $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2000-01-01'), (10000, '2000-01-01')");
            $this->pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL $isolation");
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec("DELETE FROM $table WHERE id >= 1 AND id <= 10000 AND created < '2026-01-01'");
                $blocked = false;
                try {
                    $writer->exec("INSERT INTO $table (created) VALUES ('2099-01-01')");
                } catch (PDOException $e) {
                    $this->assertSame(1205, (int)$e->errorInfo[1]);
                    $blocked = true;
                }
                fwrite(STDOUT, json_encode(['benchmark' => 'end_of_index_append', 'isolation' => $isolation, 'blocked' => $blocked]) . PHP_EOL);
                if ($isolation === 'READ COMMITTED') {
                    $this->assertFalse($blocked);
                }
            } finally {
                $this->pdo->rollBack();
            }
            $writer->exec("INSERT INTO $table (created) VALUES ('2099-01-01')");
            $this->assertGreaterThan(10000, (int)$writer->lastInsertId());
        }
    }

    /** @group benchmark */
    public function testTimestampDistributionBenchmark(): void
    {
        $table = $this->table('access_logs');
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $rows = 20000;
        foreach ([1, 20] as $stride) {
            foreach ([10, 50, 90] as $expiredPercent) {
                foreach (['ordered', 'interleaved'] as $distribution) {
                    $this->pdo->exec("DELETE FROM $table");
                    $expected = [];
                    for ($offset = 0; $offset < $rows; $offset += 500) {
                        $values = [];
                        for ($i = $offset; $i < min($offset + 500, $rows); ++$i) {
                            $id = 1 + $i * $stride;
                            $expired = $distribution === 'ordered'
                                ? $i < intdiv($rows * $expiredPercent, 100)
                                : $i % 100 < $expiredPercent;
                            $created = $expired ? '2000-01-01' : '2099-01-01';
                            $values[] = "($id, '$created')";
                            if (!$expired) {
                                $expected[] = $id;
                            }
                        }
                        $this->pdo->exec("INSERT INTO $table (id, created) VALUES " . implode(',', $values));
                    }
                    $db = $this->adapter('AccessLog', 'access_logs');
                    $this->assertTrue($db->acquireLock());
                    $lookups = 0;
                    try {
                        $high = $db->highWaterMark();
                        $start = hrtime(true);
                        $result = (new LogRetentionSweep())->run(0, $high, $cutoff,
                            function ($cursor, $high) use ($db, &$lookups) {
                                ++$lookups;
                                return $db->nextId($cursor, $high);
                            },
                            [$db, 'processBatch'], static function () {}, [$db, 'ownsLock']);
                        $elapsedMs = (hrtime(true) - $start) / 1e6;
                    } finally {
                        $db->releaseLock();
                    }
                    $this->assertTrue($result['complete']);
                    $this->assertSame($rows - count($expected), $result['affected']);
                    $this->assertSame($expected, $this->ids('access_logs'));
                    fwrite(STDOUT, json_encode([
                        'benchmark' => 'timestamp_distribution',
                        'rows' => $rows,
                        'id_stride' => $stride,
                        'expired_percent' => $expiredPercent,
                        'distribution' => $distribution,
                        'high_water_mark' => $high,
                        'batches' => $result['batches'],
                        'lookups' => $lookups,
                        'deleted' => $result['affected'],
                        'elapsed_ms' => $elapsedMs,
                    ], JSON_THROW_ON_ERROR) . PHP_EOL);
                }
            }
        }
    }

    /** @group benchmark */
    public function testCheckpointPersistenceBenchmark(): void
    {
        $table = $this->table('access_logs');
        $stateTable = $this->table('admin_settings');
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $high = 200000;
        $batchSize = 10000;
        // Each range retains one recent row, forcing restart to revisit it.
        foreach ([1, 5, 0] as $interval) {
            $this->pdo->exec("DELETE FROM $table");
            $this->pdo->exec("DELETE FROM $stateTable");
            for ($id = 1; $id <= $high; $id += $batchSize) {
                $recent = $id + 1;
                $this->pdo->exec("INSERT INTO $table (id, created) VALUES ($id, '2000-01-01'), ($recent, '2099-01-01')");
            }
            $db = $this->adapter('AccessLog', 'access_logs');
            $this->assertTrue($db->acquireLock());
            $writes = 0;
            $processed = 0;
            $sweep = new LogRetentionSweep($batchSize);
            $save = function ($cursor) use ($db, $interval, $cutoff, $high, &$writes, &$processed) {
                if ($interval > 0 && ($processed % $interval === 0 || $cursor === $high)) {
                    $db->saveCheckpoint(LogRetentionCheckpoint::encode(30, $cutoff, $cursor, $high));
                    ++$writes;
                }
            };
            $batch = function ($first, $last, $cutoff, $dryRun) use ($db, &$processed) {
                $count = $db->processBatch($first, $last, $cutoff, $dryRun);
                ++$processed;
                return $count;
            };
            try {
                $start = hrtime(true);
                $first = $sweep->run(0, $high, $cutoff, [$db, 'nextId'], $batch, $save,
                    static function () use (&$processed) { return $processed < 13; });
                $initialMs = (hrtime(true) - $start) / 1e6;
            } finally {
                $db->releaseLock();
            }
            $this->assertFalse($first['complete']);
            $this->assertSame(13, $first['affected']);
            $initialWrites = $writes;
            $db = $this->adapter('AccessLog', 'access_logs', $this->connect());
            $this->assertTrue($db->acquireLock());
            try {
                $start = hrtime(true);
                $state = LogRetentionCheckpoint::resume($db->loadCheckpoint(), 30, $cutoff, $high);
                $cursor = $state['cursor'] ?? 0;
                $second = $sweep->run($cursor, $high, $cutoff, [$db, 'nextId'],
                    [$db, 'processBatch'], static function () {}, static function () { return true; });
                $resumeMs = (hrtime(true) - $start) / 1e6;
            } finally {
                $db->releaseLock();
            }
            $this->assertTrue($second['complete']);
            $this->assertSame(7, $second['affected']);
            $this->assertSame(20, count($this->ids('access_logs')));
            $this->assertSame($interval === 0 ? 0 : intdiv(13, $interval), $initialWrites);
            fwrite(STDOUT, json_encode([
                'benchmark' => 'checkpoint_recovery_sparse_mixed',
                'interval_batches' => $interval,
                'initial_ms' => $initialMs,
                'checkpoint_writes' => $initialWrites,
                'resume_cursor' => $cursor,
                'recovery_ms' => $resumeMs,
                'recovery_batches' => $second['batches'],
            ], JSON_THROW_ON_ERROR) . PHP_EOL);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testManagedRetentionBlockDiagnostics(): void
    {
        class_alias(RetentionModelTestApp::class, 'App');
        class_alias(RetentionModelTestBase::class, 'AppModel');
        require_once __DIR__ . '/../Model/Task.php';
        require_once __DIR__ . '/../Model/Job.php';
        $task = ['type' => 'Admin', 'action' => Task::RETENTION_ACTION, 'enabled' => true,
            'user_id' => 0, 'timer' => Task::RETENTION_INTERVAL, 'next_execution_time' => 100, 'last_job_id' => 7];
        $this->assertStringContainsString('missing job 7', Task::retentionBlockReason($task, null, 100));
        foreach ([Job::STATUS_CREATED, Job::STATUS_WAITING, Job::STATUS_RUNNING] as $status) {
            $this->assertStringContainsString('nonterminal job 7', Task::retentionBlockReason($task, ['id' => 7, 'status' => $status], 100));
        }
        foreach ([Job::STATUS_FAILED, Job::STATUS_COMPLETED] as $status) {
            $this->assertNull(Task::retentionBlockReason($task, ['id' => 7, 'status' => $status], 100));
        }
        $this->assertStringContainsString('unexpected status 99', Task::retentionBlockReason($task, ['id' => 7, 'status' => 99], 100));
        $this->assertNull(Task::retentionBlockReason($task, null, 99));
        $this->assertNull(Task::retentionBlockReason(array_replace($task, ['enabled' => false]), null, 100));
        $this->assertNull(Task::retentionBlockReason(array_replace($task, ['last_job_id' => null]), null, 100));
        $this->assertStringContainsString('owner or interval', Task::retentionBlockReason(array_replace($task, ['timer' => 1]), null, 100));
    }

    public function testProvisioningLockIsIndependentOfDeletion(): void
    {
        $make = function (PDO $pdo, bool $provisioning) {
            return new LogRetentionDatabase(new RetentionDatabaseTestModel('AccessLog', 'access_logs', $pdo, $this->prefix), new DateTimeZone('UTC'), $provisioning);
        };
        $deletion = $make($this->pdo, false);
        $provisioning = $make($this->connect(), true);
        $competitor = $make($this->connect(), true);
        $this->assertTrue($deletion->acquireLock());
        try {
            $this->assertTrue($provisioning->acquireLock());
            try {
                $this->assertFalse($competitor->acquireLock());
                $this->assertTrue($deletion->ownsLock());
                $this->assertTrue($provisioning->ownsLock());
                try {
                    $provisioning->loadCheckpoint();
                    $this->fail('Provisioning lock must not authorise checkpoint access.');
                } catch (LogicException $e) {
                    $this->assertStringContainsString('Provisioning lock', $e->getMessage());
                }
            } finally {
                $provisioning->releaseLock();
            }
            $this->assertTrue($competitor->acquireLock());
            $competitor->releaseLock();
            $this->assertTrue($deletion->ownsLock());
        } finally {
            $deletion->releaseLock();
        }
    }

    public function testBoundedDeletionAndDryRunAcrossAllTables(): void
    {
        $cutoff = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        foreach (['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'] as $alias => $table) {
            $this->seed($table);
            $db = $this->adapter($alias, $table);
            $this->assertTrue($db->acquireLock());
            try {
                $this->assertSame(30001, $db->highWaterMark());
                $this->assertSame(30000, $db->nextId(10000, 30001));
                $this->assertSame(2, $db->processBatch(1, 10000, $cutoff, true));
                $this->assertSame([1, 2, 10000, 30000, 30001], $this->ids($table));
                $this->assertSame(2, $db->processBatch(1, 10000, $cutoff, false));
                $this->assertSame(0, $db->processBatch(1, 10000, $cutoff, false));
                $this->assertSame([2, 30000, 30001], $this->ids($table));
                $this->assertSame(1, $db->processBatch(30000, 30001, $cutoff, false));
                $this->assertSame([2, 30001], $this->ids($table));
            } finally {
                $db->releaseLock();
            }
        }
    }

    public function testNonTransactionalLogTableIsRejectedBeforeDeletion(): void
    {
        $table = $this->table('access_logs');
        $this->pdo->exec("ALTER TABLE $table ENGINE=MyISAM");
        $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2000-01-01')");
        $db = $this->adapter('AccessLog', 'access_logs');
        try {
            $db->acquireLock();
            $this->fail('Expected nontransactional table to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('InnoDB', $e->getMessage());
            $this->assertSame([1], $this->ids('access_logs'));
        }
    }

    public function testNativeInternalStateUsesAtomicUpsertWithoutCallbacks(): void
    {
        $model = new RetentionDatabaseTestModel('AccessLog', 'access_logs', $this->pdo, $this->prefix);
        $dialect = $model->getSqlDialect();
        $this->assertNull(AdminSetting::readInternalState($dialect, 'internal_test'));
        AdminSetting::writeInternalState($dialect, 'internal_test', 'first');
        AdminSetting::writeInternalState($dialect, 'internal_test', 'second');
        $this->assertSame('second', AdminSetting::readInternalState($dialect, 'internal_test'));
        $this->pdo->beginTransaction();
        try {
            $this->expectException(RuntimeException::class);
            AdminSetting::writeInternalState($dialect, 'internal_test', 'third');
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testLockContentionAndCheckpointRecovery(): void
    {
        $a = $this->adapter('AuditLog', 'audit_logs');
        $b = $this->adapter('AuditLog', 'audit_logs', $this->connect());
        $sameSession = $this->adapter('AuditLog', 'audit_logs');
        $otherTable = $this->adapter('Log', 'logs');
        $this->assertTrue($a->acquireLock());
        try {
            $this->assertFalse($b->acquireLock());
            $this->assertFalse($sameSession->acquireLock());
            $this->assertTrue($otherTable->acquireLock());
            $otherTable->releaseLock();
            $this->assertNull($a->loadCheckpoint());
            $a->saveCheckpoint('first');
            $a->saveCheckpoint('second');
        } finally {
            $a->releaseLock();
        }
        $this->assertTrue($b->acquireLock());
        try {
            $this->assertSame('second', $b->loadCheckpoint());
        } finally {
            $b->releaseLock();
        }
    }

    public function testSessionTerminationReleasesLock(): void
    {
        $a = $this->adapter('AuditLog', 'audit_logs', $this->connect());
        $b = $this->adapter('AuditLog', 'audit_logs', $this->connect());
        $this->assertTrue($a->acquireLock());
        $this->assertFalse($b->acquireLock());
        unset($a);
        // Closing a client connection does not synchronously wait for the
        // server to finish session cleanup. Poll the non-blocking acquisition
        // with a bounded deadline instead of assuming immediate availability.
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            if ($b->acquireLock()) {
                try {
                    $this->assertTrue($b->ownsLock());
                } finally {
                    $b->releaseLock();
                }
                return;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        $this->fail('Database did not release the disconnected session lock within five seconds.');
    }

    public function testFailedDeleteRollsBackAndPreservesCheckpoint(): void
    {
        $this->seed('audit_logs');
        $table = $this->table('audit_logs');
        $trigger = $this->table('fail_delete');
        $this->pdo->exec("CREATE TRIGGER $trigger BEFORE DELETE ON $table FOR EACH ROW BEGIN
            IF OLD.id = 10000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture deletion failure'; END IF;
            END");
        $db = $this->adapter('AuditLog', 'audit_logs');
        $this->assertTrue($db->acquireLock());
        try {
            $db->saveCheckpoint('before');
            try {
                $db->processBatch(1, 10000, new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')), false);
                $this->fail('Expected database failure.');
            } catch (PDOException $e) {
                $this->assertSame('45000', $e->getCode());
            }
            $this->assertFalse($this->pdo->inTransaction());
            $this->assertSame([1, 2, 10000, 30000, 30001], $this->ids('audit_logs'));
            $this->assertSame('before', $db->loadCheckpoint());
        } finally {
            $db->releaseLock();
        }
    }

    public function testAccessLogCutoffUsesUtcRegardlessOfInputOffset(): void
    {
        $this->pdo->exec("INSERT INTO " . $this->table('access_logs') . " (id, created) VALUES
            (1, '2026-01-01 09:59:59.9999'), (2, '2026-01-01 10:00:00.0000'),
            (3, '2026-01-01 10:00:00.0001'), (4, '2026-01-01 11:00:00')");
        $db = $this->adapter('AccessLog', 'access_logs', null, new DateTimeZone('Europe/Tallinn'));
        $this->assertTrue($db->acquireLock());
        try {
            foreach (['2026-01-01 12:00:00+02:00', '2026-01-01 05:00:00-05:00'] as $time) {
                $this->assertSame(1, $db->processBatch(1, 4, new DateTimeImmutable($time), true));
            }
            $this->assertSame(1, $db->processBatch(1, 4, new DateTimeImmutable('2026-01-01 12:00:00+02:00'), false));
            $this->assertSame([2, 3, 4], $this->ids('access_logs'));
        } finally {
            $db->releaseLock();
        }
    }

    public function testLocalLogCutoffUsesExplicitWriterTimezone(): void
    {
        foreach (['AuditLog' => 'audit_logs', 'Log' => 'logs'] as $alias => $table) {
            $this->pdo->exec("INSERT INTO " . $this->table($table) . " (id, created) VALUES
                (1, '2026-01-01 11:59:59'), (2, '2026-01-01 12:00:00'), (3, '2026-01-01 12:00:01')");
            $db = $this->adapter($alias, $table, null, new DateTimeZone('Europe/Tallinn'));
            $this->assertTrue($db->acquireLock());
            try {
                $this->assertSame(1, $db->processBatch(1, 3, new DateTimeImmutable('2026-01-01 10:00:00+00:00'), false));
                $this->assertSame([2, 3], $this->ids($table));
            } finally {
                $db->releaseLock();
            }
        }
    }

    public function testAmbiguousLocalHourIsRetainedUntilBothOccurrencesExpire(): void
    {
        $this->pdo->exec("INSERT INTO " . $this->table('logs') . " (id, created) VALUES
            (1, '2026-10-25 02:59:59'), (2, '2026-10-25 03:00:00'),
            (3, '2026-10-25 03:30:00'), (4, '2026-10-25 04:00:00')");
        $db = $this->adapter('Log', 'logs', null, new DateTimeZone('Europe/Tallinn'));
        $this->assertTrue($db->acquireLock());
        try {
            foreach (['2026-10-25 00:30:00+00:00', '2026-10-25 01:30:00+00:00'] as $time) {
                $this->assertSame(1, $db->processBatch(1, 4, new DateTimeImmutable($time), true));
            }
            $this->assertSame(1, $db->processBatch(1, 4, new DateTimeImmutable('2026-10-25 01:30:00+00:00'), false));
            $this->assertSame([2, 3, 4], $this->ids('logs'));
            $this->assertSame(2, $db->processBatch(1, 4, new DateTimeImmutable('2026-10-25 02:00:00+00:00'), false));
            $this->assertSame([4], $this->ids('logs'));
        } finally {
            $db->releaseLock();
        }
    }

    public function testLocalTableRequiresExplicitTimezone(): void
    {
        $db = new LogRetentionDatabase(new RetentionDatabaseTestModel('Log', 'logs', $this->pdo, $this->prefix));
        $this->assertSame('disabled', (new LogRetentionRunner())->run($db, static function () { return 0; })['status']);
        $this->expectException(InvalidArgumentException::class);
        (new LogRetentionRunner())->run($db, static function () { return 30; });
    }

    public function testRunnerStopsOnPolicyChangeAndResumesRealDatabaseSweep(): void
    {
        $this->pdo->exec("INSERT INTO " . $this->table('access_logs') . " (id, created) VALUES
            (1, '2000-01-01 00:00:00'), (10000, '2000-01-01 00:00:00'),
            (30000, '2000-01-01 00:00:00'), (30001, '2099-01-01 00:00:00')");
        $db = $this->adapter('AccessLog', 'access_logs');
        $runner = new LogRetentionRunner();
        $dry = $runner->run($db, static function () { return 30; }, true, 1800, 0);
        $this->assertSame('complete', $dry['status']);
        $this->assertSame(3, $dry['affected']);
        $this->assertSame([1, 10000, 30000, 30001], $this->ids('access_logs'));
        $reads = 0;
        $partial = $runner->run($db, static function () use (&$reads) {
            return ++$reads <= 3 ? 30 : 365;
        }, false, 1800, 0);
        $this->assertSame('partial', $partial['status']);
        $this->assertSame('policy_changed', $partial['reason']);
        $this->assertSame(10000, $partial['cursor']);
        $this->assertSame(2, $partial['affected']);
        $this->assertSame([30000, 30001], $this->ids('access_logs'));
        $this->assertFalse($db->ownsLock());
        $second = $this->adapter('AccessLog', 'access_logs', $this->connect());
        $resumed = $runner->run($second, static function () { return 30; }, false, 1800, 0);
        $this->assertSame('complete', $resumed['status']);
        $this->assertSame(30001, $resumed['cursor']);
        $this->assertSame(1, $resumed['affected']);
        $this->assertSame([30001], $this->ids('access_logs'));
        $this->assertFalse($second->ownsLock());
    }

    public function testMaximumBatchRangeMatchesSweepLimit(): void
    {
        $this->seed('logs');
        $db = $this->adapter('Log', 'logs');
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $this->assertTrue($db->acquireLock());
        try {
            $this->assertSame(3, $db->processBatch(1, LogRetentionSweep::MAX_BATCH_SIZE, $cutoff, true));
            $this->expectException(InvalidArgumentException::class);
            $db->processBatch(1, LogRetentionSweep::MAX_BATCH_SIZE + 1, $cutoff, true);
        } finally {
            $db->releaseLock();
        }
    }

    public function testLostLockCannotReleaseAnotherSessionLockOrBeReused(): void
    {
        $a = $this->adapter('Log', 'logs');
        $b = $this->adapter('Log', 'logs', $this->connect());
        $this->assertTrue($a->acquireLock());
        $this->pdo->query('SELECT RELEASE_ALL_LOCKS()')->closeCursor();
        $this->assertTrue($b->acquireLock());
        try {
            $this->assertFalse($a->ownsLock());
            try {
                $a->releaseLock();
                $this->fail('Expected release failure.');
            } catch (RuntimeException $e) {
                $this->assertSame('Could not release retention lock.', $e->getMessage());
            }
            $this->assertTrue($b->ownsLock());
            $this->expectExceptionMessage('Retention requires a usable session');
            $a->acquireLock();
        } finally {
            $b->releaseLock();
        }
    }

    public function testDryRunWithoutLockIsRejected(): void
    {
        $db = $this->adapter('AccessLog', 'access_logs');
        $this->expectExceptionMessage('Retention batch requires an owned database lock.');
        $db->processBatch(1, 10000, new DateTimeImmutable('2026-01-01'), true);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testExplicitRetentionOnActualLogModelsPreservesLegacyContract(): void
    {
        if (!class_exists('App', false)) {
            class_alias(RetentionModelTestApp::class, 'App');
        }
        if (!class_exists('AppModel', false)) {
            class_alias(RetentionModelTestBase::class, 'AppModel');
        }
        require_once __DIR__ . '/../Lib/Tools/LogRetentionService.php';
        foreach (['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'] as $alias => $table) {
            require_once __DIR__ . '/../Model/' . $alias . '.php';
            $model = (new ReflectionClass($alias))->newInstanceWithoutConstructor();
            $model->alias = $alias;
            $model->table = $table;
            $model->retentionDatasource = new RetentionDatabaseTestModel($alias, $table, $this->pdo, $this->prefix);
            $this->pdo->exec("INSERT INTO " . $this->table($table) . " (id, created) VALUES
                (1, '2000-01-01 00:00:00'), (2, '2099-01-01 00:00:00')");
            $dry = LogRetentionService::runExplicit($model, 30, true, new DateTimeZone('UTC'));
            $this->assertSame('complete', $dry['status']);
            $this->assertSame(1, $dry['affected']);
            $this->assertSame([1, 2], $this->ids($table));
            $result = LogRetentionService::runExplicit($model, 30, false, new DateTimeZone('UTC'));
            $this->assertSame('complete', $result['status']);
            $this->assertSame(1, $result['affected']);
            $this->assertSame([2], $this->ids($table));
        }
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM " . $this->table('admin_settings'))->fetchColumn());

        // Exercise the original public access-log method with a non-UTC cutoff.
        $model = (new ReflectionClass('AccessLog'))->newInstanceWithoutConstructor();
        $model->alias = 'AccessLog';
        $model->table = 'access_logs';
        $model->retentionDatasource = new RetentionDatabaseTestModel('AccessLog', 'access_logs', $this->pdo, $this->prefix);
        $this->pdo->exec("INSERT INTO " . $this->table('access_logs') . " (id, created) VALUES
            (10000, '2025-12-31 09:59:59.9999'), (10001, '2025-12-31 10:00:00'),
            (30000, '2025-12-30 00:00:00')");
        $cutoff = new DateTime('2025-12-31 12:00:00', new DateTimeZone('Europe/Tallinn'));
        $this->assertSame(2, $model->deleteOldLogs($cutoff));
        $this->assertSame([2, 10001], $this->ids('access_logs'));
        $this->assertSame('2025-12-31 12:00:00+02:00', $cutoff->format('Y-m-d H:i:sP'));
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM " . $this->table('admin_settings'))->fetchColumn());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLogShellCommandsAgainstMariaDb(): void
    {
        class_alias(RetentionModelTestApp::class, 'App');
        class_alias(RetentionModelTestBase::class, 'AppModel');
        class_alias(RetentionShellTestBase::class, 'AppShell');
        if (!function_exists('__')) {
            function __($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }
        }
        if (!function_exists('__n')) {
            function __n($singular, $plural, $count, ...$args) { return sprintf($count === 1 ? $singular : $plural, $count); }
        }
        require_once __DIR__ . '/../Lib/Tools/LogRetentionService.php';
        require_once __DIR__ . '/../Console/Command/LogShell.php';
        $shell = new class extends LogShell {
            protected function retentionConfigFile(): string { return $this->configFile; }
        };
        foreach (['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'] as $alias => $table) {
            require_once __DIR__ . '/../Model/' . $alias . '.php';
            $model = (new ReflectionClass($alias))->newInstanceWithoutConstructor();
            $model->alias = $alias;
            $model->table = $table;
            $model->retentionDatasource = new RetentionDatabaseTestModel($alias, $table, $this->pdo, $this->prefix);
            $shell->$alias = $model;
            $this->pdo->exec("INSERT INTO " . $this->table($table) . " (id, created) VALUES
                (1, '2000-01-01 00:00:00'), (2, '2099-01-01 00:00:00')");
        }
        $shell->configFile = tempnam(sys_get_temp_dir(), 'retention-config-');
        try {
            file_put_contents($shell->configFile, '<?php $config = ["MISP" => []];');
            $shell->args = ['30'];
            $shell->params = ['dry-run' => true, 'log-timezone' => 'UTC'];
            foreach (['auditLogRetention', 'accessLogRetention', 'applicationLogRetention'] as $command) {
                $this->assertTrue($shell->$command());
            }
            foreach (['audit_logs', 'access_logs', 'logs'] as $table) {
                $this->assertSame([1, 2], $this->ids($table));
            }
            $this->assertStringContainsString('eligible=1', implode("\n", $shell->output));
            $shell->params = [];
            $shell->args = ['0'];
            $this->assertFalse($shell->accessLogRetention());
            $this->assertFalse($shell->auditLogRetention());
            $shell->args = ['30'];
            $this->assertFalse($shell->auditLogRetention()); // writer timezone missing
            $this->assertSame([1, 2], $this->ids('audit_logs'));
            file_put_contents($shell->configFile, '<?php $config = ["MISP" => ["access_log_retention_days" => 30]];');
            $shell->args = [];
            $shell->params = ['dry-run' => true];
            $this->assertTrue($shell->retention());
            $this->assertSame([1, 2], $this->ids('access_logs'));
            $shell->params = [];
            $this->assertTrue($shell->retention());
            $this->assertSame([2], $this->ids('access_logs'));
            $this->assertSame([1, 2], $this->ids('audit_logs'));
            $this->assertSame([1, 2], $this->ids('logs'));
            file_put_contents($shell->configFile, '<?php $config = ["MISP" => ["audit_log_retention_days" => 30, "log_retention_timezone" => "UTC"]];');
            $shell->params = ['dry-run' => true, 'log-timezone' => 'Europe/Tallinn'];
            $this->assertFalse($shell->retention());
            $this->assertSame([1, 2], $this->ids('audit_logs'));
            $shell->params = ['dry-run' => true];
            $this->assertTrue($shell->retention());
            $shell->args = ['30'];
            $this->assertTrue($shell->applicationLogRetention());
            $shell->params = [];
            $this->assertTrue($shell->retention());
            $this->assertSame([2], $this->ids('audit_logs'));
            $this->assertSame([1, 2], $this->ids('logs'));
        } finally {
            unlink($shell->configFile);
        }
    }

    public function testInvalidLocalPoliciesDoNotBlockAccessLogRetention(): void
    {
        require_once __DIR__ . '/../Lib/Tools/LogRetentionService.php';
        $tables = ['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'];
        foreach ($tables as $table) {
            $this->pdo->exec("INSERT INTO " . $this->table($table) . " (id, created) VALUES (1, '2000-01-01')");
        }
        $config = tempnam(sys_get_temp_dir(), 'retention-config-');
        try {
            file_put_contents($config, '<?php $config = ["MISP" => ["audit_log_retention_days" => 30, "access_log_retention_days" => 30, "log_retention_days" => "invalid"]];');
            $results = [];
            $success = LogRetentionService::runAllConfigured(
                function ($alias) use ($tables, $config) {
                    return LogRetentionService::runConfigured(new RetentionDatabaseTestModel($alias, $tables[$alias], $this->pdo, $this->prefix), $config);
                },
                static function ($category, $result, $progress) use (&$results) {
                    if ($result !== null) {
                        $results[$category] = $result;
                    }
                },
                static function ($category, $error, $progress) { throw $error; }
            );
            $this->assertFalse($success);
            $this->assertSame(['blocked', 'complete', 'blocked'], array_column($results, 'status'));
            $this->assertSame([1], $this->ids('audit_logs'));
            $this->assertSame([], $this->ids('access_logs'));
            $this->assertSame([1], $this->ids('logs'));
            $this->assertSame(0, $results['audit_logs']['affected']);
            $this->assertSame(1, $results['access_logs']['affected']);
        } finally {
            unlink($config);
        }
    }

    public function testConfiguredSweepStopsAfterWriterTimezoneChanges(): void
    {
        require_once __DIR__ . '/../Lib/Tools/LogRetentionService.php';
        $table = $this->table('audit_logs');
        $settings = $this->table('system_settings');
        $trigger = $this->table('change_timezone');
        $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2000-01-01'), (20000, '2000-01-01')");
        $this->pdo->exec("INSERT INTO $settings (setting, value) VALUES ('MISP.audit_log_retention_days', '30'), ('MISP.log_retention_timezone', '"
            . json_encode('UTC') . "')");
        $this->pdo->exec("CREATE TRIGGER $trigger AFTER DELETE ON $table FOR EACH ROW
            UPDATE $settings SET value = '" . json_encode('Europe/Tallinn') . "' WHERE setting = 'MISP.log_retention_timezone'");
        $config = tempnam(sys_get_temp_dir(), 'retention-config-');
        try {
            file_put_contents($config, '<?php $config = ["MISP" => ["system_setting_db" => true]];');
            $model = new RetentionDatabaseTestModel('AuditLog', 'audit_logs', $this->pdo, $this->prefix);
            $result = LogRetentionService::runConfigured($model, $config);
            $this->assertSame('blocked', $result['status']);
            $this->assertSame(1, $result['affected']);
            $this->assertSame(1, $result['batches']);
            $this->assertStringContainsString('writer timezone changed', $result['reason']);
            $this->assertSame([20000], $this->ids('audit_logs'));
            $db = $this->adapter('AuditLog', 'audit_logs');
            $this->assertTrue($db->acquireLock());
            try {
                $checkpoint = json_decode($db->loadCheckpoint(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame(10000, $checkpoint['cursor']);
                $this->assertSame('UTC', $checkpoint['timezone']);
            } finally {
                $db->releaseLock();
            }
        } finally {
            unlink($config);
        }
    }

    public function testDeletionWithoutLockIsRejected(): void
    {
        $this->seed('logs');
        $db = $this->adapter('Log', 'logs');
        $this->expectException(RuntimeException::class);
        $db->processBatch(1, 10000, new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')), false);
    }
}

/** Minimal model/datasource boundary; all SQL executes against real MariaDB. */
class RetentionDatabaseTestModel
{
    public $alias;
    public $table;
    private $pdo;
    private $prefix;

    public function __construct(string $alias, string $table, PDO $pdo, string $prefix)
    {
        $this->alias = $alias;
        $this->table = $table;
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function getDataSource() { return $this; }
    public function getConnection(): PDO { return $this->pdo; }
    public function name($column): string { return chr(96) . $column . chr(96); }
    public function getSqlDialect()
    {
        if (!class_exists('App', false)) {
            class_alias(RetentionModelTestApp::class, 'App');
        }
        require_once __DIR__ . '/../Lib/Migration/SqlDialect.php';
        if (!class_exists('AppModel', false)) {
            class_alias(RetentionModelTestBase::class, 'AppModel');
        }
        require_once __DIR__ . '/../Model/AdminSetting.php';
        return new class($this) extends SqlDialect {
            public function flavour() { return self::FLAVOUR_MYSQL; }
        };
    }
    public function fullTableName($model, $quote = true, $schema = true): string
    {
        $name = $this->prefix . (is_object($model) ? $model->table : $model);
        return $quote ? $this->name($name) : $name;
    }
}

/** Isolated CakePHP boundary stubs; the log model classes are real. */
class RetentionModelTestApp
{
    public static function uses($class, $package) {}
}

class RetentionModelTestBase
{
    public $alias;
    public $table;
    public $retentionDatasource;

    public function getSqlDialect() { return $this->retentionDatasource->getSqlDialect(); }

    public function getDataSource()
    {
        return $this->retentionDatasource;
    }
}

class RetentionShellTestBase
{
    public $AuditLog;
    public $AccessLog;
    public $Log;
    public $args = [];
    public $params = [];
    public $output = [];
    public $configFile;

    public function param($key) { return $this->params[$key] ?? null; }
    public function out($message) { $this->output[] = $message; }
    public function err($message) { $this->output[] = $message; }
    public function error($message) { $this->output[] = $message; return false; }
}
