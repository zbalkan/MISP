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
        $this->expectException(InvalidArgumentException::class);
        new LogRetentionDatabase(new RetentionDatabaseTestModel('Log', 'logs', $this->pdo, $this->prefix));
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
        $this->assertSame(3, $db->processBatch(1, LogRetentionSweep::MAX_BATCH_SIZE, $cutoff, true));
        $this->expectException(InvalidArgumentException::class);
        $db->processBatch(1, LogRetentionSweep::MAX_BATCH_SIZE + 1, $cutoff, true);
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
    public function fullTableName($model): string
    {
        return chr(96) . $this->prefix . (is_object($model) ? $model->table : $model) . chr(96);
    }
}
