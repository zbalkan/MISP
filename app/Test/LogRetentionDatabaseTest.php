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
                    [$db, 'nextId'], [$db, 'processBatch'], [$db, 'ownsLock']);
                $this->assertTrue($result['complete']);
                $this->assertSame(1, $result['affected']);
                $this->assertSame([5000, 20001], $this->ids('access_logs'));
                $next = (new LogRetentionSweep())->run(0, $db->highWaterMark(), $cutoff,
                    [$db, 'nextId'], [$db, 'processBatch'], [$db, 'ownsLock']);
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */

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

    public function testDisabledPolicyDoNotRequireTransactionalAccessLog(): void
    {
        $table = $this->table('access_logs');
        $this->pdo->exec("ALTER TABLE $table ENGINE=MyISAM");
        $db = $this->adapter('AccessLog', 'access_logs');
        $this->assertSame('disabled', (new LogRetentionRunner())->run($db, static function () { return 0; })['status']);
        $this->expectExceptionMessage('Retention requires a transactional InnoDB log table.');
        $db->acquireLock();
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

    public function testTerminatedSessionRollsBackUncommittedDeletion(): void
    {
        $table = $this->table('access_logs');
        $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2025-01-01'), (2, '2026-01-01'), (10001, '2025-01-01')");
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $worker = $this->connect();
        $id = (int)$worker->query('SELECT CONNECTION_ID()')->fetchColumn();
        $db = $this->adapter('AccessLog', 'access_logs', $worker);
        $this->assertTrue($db->acquireLock());
        $high = $db->highWaterMark();
        // Hold the deletion transaction open to reproduce interruption before commit.
        $worker->beginTransaction();
        $this->assertSame(1, $worker->exec("DELETE FROM $table WHERE id BETWEEN 1 AND 10000 AND created < '2026-01-01'"));
        $this->pdo->exec("KILL CONNECTION $id");
        unset($db, $worker);
        $recovery = $this->adapter('AccessLog', 'access_logs', $this->connect());
        $deadline = hrtime(true) + 5_000_000_000;
        while (!$recovery->acquireLock()) {
            if (hrtime(true) >= $deadline) {
                $this->fail('Terminated session did not release its retention lock.');
            }
            usleep(10000);
        }
        try {
            $this->assertSame([1, 2, 10001], array_map('intval', $this->pdo->query("SELECT id FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
            $result = (new LogRetentionSweep())->run(
                0, $high, $cutoff, [$recovery, 'nextId'],
                [$recovery, 'processBatch'],
                static function () { return true; }
            );
            $this->assertTrue($result['complete']);
            $this->assertSame(2, $result['affected']);
            $this->assertSame([2], array_map('intval', $this->pdo->query("SELECT id FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        } finally {
            $recovery->releaseLock();
        }
    }

    public function testKilledSessionRecoversCommittedBatchWithoutCheckpoint(): void
    {
        $this->seed('access_logs');
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $worker = $this->connect();
        $connectionId = (int)$worker->query('SELECT CONNECTION_ID()')->fetchColumn();
        $db = $this->adapter('AccessLog', 'access_logs', $worker);
        $this->assertTrue($db->acquireLock());
        $high = $db->highWaterMark();
        $this->assertSame(2, $db->processBatch(1, 10000, $cutoff, false));
        $this->assertSame([2, 30000, 30001], $this->ids('access_logs'));
        // Terminate the server session after commit.
        $this->pdo->exec("KILL CONNECTION $connectionId");
        unset($db, $worker);
        $recovery = $this->adapter('AccessLog', 'access_logs', $this->connect());
        $deadline = hrtime(true) + 5_000_000_000;
        while (!$recovery->acquireLock()) {
            if (hrtime(true) >= $deadline) {
                $this->fail('Killed session did not release its retention lock.');
            }
            usleep(10000);
        }
        try {
            $result = (new LogRetentionSweep())->run(
                0, $high, $cutoff, [$recovery, 'nextId'],
                [$recovery, 'processBatch'],
                static function () { return true; }
            );
            $this->assertTrue($result['complete']);
            $this->assertSame(1, $result['affected']);
            $this->assertSame([2, 30001], $this->ids('access_logs'));
        } finally {
            $recovery->releaseLock();
        }
    }

    public function testFailedDeleteRollsBack(): void
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
            try {
                $db->processBatch(1, 10000, new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')), false);
                $this->fail('Expected database failure.');
            } catch (PDOException $e) {
                $this->assertSame('45000', $e->getCode());
            }
            $this->assertFalse($this->pdo->inTransaction());
            $this->assertSame([1, 2, 10000, 30000, 30001], $this->ids('audit_logs'));
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
        $dry = $runner->run($db, static function () { return 30; }, true, 0);
        $this->assertSame('complete', $dry['status']);
        $this->assertSame(3, $dry['affected']);
        $this->assertSame([1, 10000, 30000, 30001], $this->ids('access_logs'));
        $reads = 0;
        $partial = $runner->run($db, static function () use (&$reads) {
            return ++$reads <= 3 ? 30 : 365;
        }, false, 0);
        $this->assertSame('partial', $partial['status']);
        $this->assertSame('policy_changed', $partial['reason']);
        $this->assertSame(10000, $partial['cursor']);
        $this->assertSame(2, $partial['affected']);
        $this->assertSame([30000, 30001], $this->ids('access_logs'));
        $this->assertFalse($db->ownsLock());
        $second = $this->adapter('AccessLog', 'access_logs', $this->connect());
        $resumed = $runner->run($second, static function () { return 30; }, false, 0);
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
