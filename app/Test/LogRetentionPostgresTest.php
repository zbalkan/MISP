<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../Lib/Tools/LogRetentionDatabase.php';
require_once __DIR__ . '/../Lib/Tools/LogRetentionService.php';

class LogRetentionPostgresTest extends TestCase
{
    private $pdo;
    private $prefix;

    protected function setUp(): void
    {
        if (getenv('MISP_RETENTION_PG_DSN') === false) {
            $this->markTestSkipped('Set MISP_RETENTION_PG_DSN to a disposable PostgreSQL database.');
        }
        if (!class_exists('App', false)) {
            class_alias(RetentionPostgresApp::class, 'App');
        }
        require_once __DIR__ . '/../Lib/Migration/SqlDialect.php';
        if (!class_exists('AppModel', false)) {
            class_alias(RetentionPostgresAppModel::class, 'AppModel');
        }
        require_once __DIR__ . '/../Model/AdminSetting.php';
        $this->pdo = $this->connect();
        $this->prefix = 'retention_' . bin2hex(random_bytes(6)) . '_';
        foreach (['audit_logs', 'access_logs', 'logs'] as $table) {
            $this->pdo->exec("CREATE TABLE " . $this->table($table) . " (id SERIAL PRIMARY KEY, created TIMESTAMP NOT NULL)");
        }
        $this->pdo->exec("CREATE TABLE " . $this->table('admin_settings') . " (id SERIAL PRIMARY KEY, setting VARCHAR(255) UNIQUE NOT NULL, value TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE " . $this->table('system_settings') . " (id SERIAL PRIMARY KEY, setting VARCHAR(255) UNIQUE NOT NULL, value BYTEA NOT NULL)");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            foreach (['audit_logs', 'access_logs', 'logs', 'admin_settings', 'system_settings'] as $table) {
                $this->pdo->exec("DROP TABLE IF EXISTS " . $this->table($table));
            }
        }
    }

    private function connect(): PDO
    {
        return new PDO(getenv('MISP_RETENTION_PG_DSN'), getenv('MISP_RETENTION_TEST_USER') ?: '', getenv('MISP_RETENTION_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false,
        ]);
    }

    private function table(string $table): string { return '"' . $this->prefix . $table . '"'; }

    private function model(string $alias, string $table, ?PDO $pdo = null): RetentionPostgresModel
    {
        return new RetentionPostgresModel($alias, $table, $pdo ?? $this->pdo, $this->prefix);
    }

    public function testProvisioningLockIsIndependentOfDeletion(): void
    {
        $make = function (PDO $pdo, bool $provisioning) {
            return new LogRetentionDatabase($this->model('AccessLog', 'access_logs', $pdo), new DateTimeZone('UTC'), $provisioning);
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

    public function testBoundedDeletionLockingAndCheckpointUpsert(): void
    {
        foreach (['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'] as $alias => $table) {
            $name = $this->table($table);
            $this->pdo->exec("INSERT INTO $name (id, created) VALUES (1, '2025-01-01'), (2, '2026-01-01'), (10001, '2025-01-01')");
            $a = new LogRetentionDatabase($this->model($alias, $table), new DateTimeZone('UTC'));
            $b = new LogRetentionDatabase($this->model($alias, $table, $this->connect()), new DateTimeZone('UTC'));
            $same = new LogRetentionDatabase($this->model($alias, $table), new DateTimeZone('UTC'));
            $this->assertTrue($a->acquireLock());
            try {
                $this->assertTrue($a->ownsLock());
                $this->assertFalse($b->acquireLock());
                $this->assertFalse($same->acquireLock());
                $cutoff = new DateTimeImmutable('2026-01-01T00:00:00Z');
                $this->assertSame(10001, $a->highWaterMark());
                $this->assertSame(10001, $a->nextId(2, 10001));
                $this->assertSame(1, $a->processBatch(1, 10000, $cutoff, true));
                $this->assertSame(1, $a->processBatch(1, 10000, $cutoff, false));
                $a->saveCheckpoint('first');
                $a->saveCheckpoint('second');
                $this->assertSame('second', $a->loadCheckpoint());
            } finally {
                $a->releaseLock();
            }
            $this->assertTrue($b->acquireLock());
            try {
                $this->assertSame('second', $b->loadCheckpoint());
                $this->assertSame(1, $b->processBatch(10001, 10001, $cutoff, false));
            } finally {
                $b->releaseLock();
            }
            $this->assertSame([2], array_map('intval', $this->pdo->query("SELECT id FROM $name")->fetchAll(PDO::FETCH_COLUMN)));
        }
    }

    public function testCommitExceptionDoesNotAdvanceCheckpoint(): void
    {
        foreach ([false, true] as $committed) {
            $table = $this->table('access_logs');
            $this->pdo->exec("DELETE FROM $table");
            $this->pdo->exec("DELETE FROM " . $this->table('admin_settings'));
            $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2025-01-01'), (2, '2026-01-01'), (10000, '2025-12-31'), (30000, '2025-01-01'), (30001, '2026-01-02')");
            $worker = new class(getenv('MISP_RETENTION_PG_DSN'), getenv('MISP_RETENTION_TEST_USER') ?: '', getenv('MISP_RETENTION_TEST_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
            ]) extends PDO {
                public bool $commitBeforeException = false;
                public function commit(): bool
                {
                    if ($this->commitBeforeException) {
                        parent::commit();
                    }
                    throw new PDOException('Injected commit acknowledgement failure.');
                }
            };
            $worker->commitBeforeException = $committed;
            $db = new LogRetentionDatabase($this->model('AccessLog', 'access_logs', $worker), new DateTimeZone('UTC'));
            $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
            $this->assertTrue($db->acquireLock());
            $high = $db->highWaterMark();
            $checkpoint = LogRetentionCheckpoint::encode(30, $cutoff, 0, $high);
            try {
                $db->saveCheckpoint($checkpoint);
                try {
                    (new LogRetentionSweep())->run(
                        0, $high, $cutoff, [$db, 'nextId'], [$db, 'processBatch'],
                        function ($cursor) use ($db, $cutoff, $high) {
                            $db->saveCheckpoint(LogRetentionCheckpoint::encode(30, $cutoff, $cursor, $high));
                        },
                        static function () { return true; }
                    );
                    $this->fail('Expected injected commit exception.');
                } catch (PDOException $e) {
                    $this->assertSame('Injected commit acknowledgement failure.', $e->getMessage());
                }
                $this->assertFalse($worker->inTransaction());
                $this->assertSame($checkpoint, $db->loadCheckpoint());
                $this->assertSame($committed ? [2, 30000, 30001] : [1, 2, 10000, 30000, 30001], array_map('intval', $this->pdo->query("SELECT id FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
            } finally {
                $db->releaseLock();
            }
            $recovery = new LogRetentionDatabase($this->model('AccessLog', 'access_logs', $this->connect()), new DateTimeZone('UTC'));
            $this->assertTrue($recovery->acquireLock());
            try {
                $state = LogRetentionCheckpoint::resume($recovery->loadCheckpoint(), 30, $cutoff, $high);
                $result = (new LogRetentionSweep())->run(
                    $state['cursor'], $high, $cutoff, [$recovery, 'nextId'], [$recovery, 'processBatch'],
                    function ($cursor) use ($recovery, $cutoff, $high) {
                        $recovery->saveCheckpoint(LogRetentionCheckpoint::encode(30, $cutoff, $cursor, $high));
                    },
                    static function () { return true; }
                );
                $this->assertTrue($result['complete']);
                $this->assertSame($committed ? 1 : 3, $result['affected']);
                $this->assertSame([2, 30001], array_map('intval', $this->pdo->query("SELECT id FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
                $this->assertSame(LogRetentionCheckpoint::encode(30, $cutoff, $high, $high), $recovery->loadCheckpoint());
            } finally {
                $recovery->releaseLock();
            }
        }
    }

    public function testTerminatedSessionRollsBackUncommittedDeletion(): void
    {
        $table = $this->table('access_logs');
        $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2025-01-01'), (2, '2026-01-01'), (10001, '2025-01-01')");
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $worker = $this->connect();
        $id = (int)$worker->query('SELECT pg_backend_pid()')->fetchColumn();
        $db = new LogRetentionDatabase($this->model('AccessLog', 'access_logs', $worker), new DateTimeZone('UTC'));
        $this->assertTrue($db->acquireLock());
        $high = $db->highWaterMark();
        $checkpoint = LogRetentionCheckpoint::encode(30, $cutoff, 0, $high);
        $db->saveCheckpoint($checkpoint);
        // Hold the deletion transaction open to reproduce interruption before commit.
        $worker->beginTransaction();
        $this->assertSame(1, $worker->exec("DELETE FROM $table WHERE id BETWEEN 1 AND 10000 AND created < '2026-01-01'"));
        $this->assertTrue((bool)$this->pdo->query("SELECT pg_terminate_backend($id, 5000)")->fetchColumn());
        unset($db, $worker);
        $recovery = new LogRetentionDatabase($this->model('AccessLog', 'access_logs', $this->connect()), new DateTimeZone('UTC'));
        $deadline = hrtime(true) + 5_000_000_000;
        while (!$recovery->acquireLock()) {
            if (hrtime(true) >= $deadline) {
                $this->fail('Terminated session did not release its retention lock.');
            }
            usleep(10000);
        }
        try {
            $this->assertSame([1, 2, 10001], array_map('intval', $this->pdo->query("SELECT id FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
            $this->assertSame($checkpoint, $recovery->loadCheckpoint());
            $state = LogRetentionCheckpoint::resume($recovery->loadCheckpoint(), 30, $cutoff, $high);
            $result = (new LogRetentionSweep())->run(
                $state['cursor'], $high, $cutoff, [$recovery, 'nextId'],
                [$recovery, 'processBatch'],
                function ($cursor) use ($recovery, $cutoff, $high) {
                    $recovery->saveCheckpoint(LogRetentionCheckpoint::encode(30, $cutoff, $cursor, $high));
                },
                static function () { return true; }
            );
            $this->assertTrue($result['complete']);
            $this->assertSame(2, $result['affected']);
            $this->assertSame([2], array_map('intval', $this->pdo->query("SELECT id FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
            $this->assertSame(LogRetentionCheckpoint::encode(30, $cutoff, $high, $high), $recovery->loadCheckpoint());
        } finally {
            $recovery->releaseLock();
        }
    }

    public function testTerminatedBackendRecoversCommittedBatchWithoutCheckpoint(): void
    {
        $table = $this->table('access_logs');
        $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2025-01-01'), (2, '2026-01-01'), (10000, '2025-12-31'), (30000, '2025-01-01'), (30001, '2026-01-02')");
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $worker = $this->connect();
        $pid = (int)$worker->query('SELECT pg_backend_pid()')->fetchColumn();
        $db = new LogRetentionDatabase($this->model('AccessLog', 'access_logs', $worker), new DateTimeZone('UTC'));
        $this->assertTrue($db->acquireLock());
        $high = $db->highWaterMark();
        $checkpoint = LogRetentionCheckpoint::encode(30, $cutoff, 0, $high);
        $db->saveCheckpoint($checkpoint);
        $this->assertSame(2, $db->processBatch(1, 10000, $cutoff, false));
        $ids = function () use ($table) {
            return array_map('intval', $this->pdo->query("SELECT id FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        };
        $this->assertSame([2, 30000, 30001], $ids());
        $this->assertTrue((bool)$this->pdo->query("SELECT pg_terminate_backend($pid, 5000)")->fetchColumn());
        try {
            $db->saveCheckpoint(LogRetentionCheckpoint::encode(30, $cutoff, 10000, $high));
            $this->fail('A terminated backend must not advance the checkpoint.');
        } catch (PDOException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        unset($db, $worker);
        $recovery = new LogRetentionDatabase($this->model('AccessLog', 'access_logs', $this->connect()), new DateTimeZone('UTC'));
        $this->assertTrue($recovery->acquireLock());
        try {
            $this->assertSame($checkpoint, $recovery->loadCheckpoint());
            $state = LogRetentionCheckpoint::resume($recovery->loadCheckpoint(), 30, $cutoff, $high);
            $this->assertSame(0, $state['cursor']);
            $result = (new LogRetentionSweep())->run(
                $state['cursor'], $high, $cutoff, [$recovery, 'nextId'],
                [$recovery, 'processBatch'],
                function ($cursor) use ($recovery, $cutoff, $high) {
                    $recovery->saveCheckpoint(LogRetentionCheckpoint::encode(30, $cutoff, $cursor, $high));
                },
                static function () { return true; }
            );
            $this->assertTrue($result['complete']);
            $this->assertSame(1, $result['affected']);
            $this->assertSame([2, 30001], $ids());
            $this->assertSame(LogRetentionCheckpoint::encode(30, $cutoff, $high, $high), $recovery->loadCheckpoint());
        } finally {
            $recovery->releaseLock();
        }
    }

    public function testConfiguredPolicyReadsByteaAndLegacyCutoffRemainsAvailable(): void
    {
        $settings = $this->table('system_settings');
        $table = $this->table('access_logs');
        $this->pdo->exec("INSERT INTO $settings (setting, value) VALUES ('MISP.access_log_retention_days', convert_to('30', 'UTF8'))");
        $this->pdo->exec("INSERT INTO $table (id, created) VALUES (1, '2000-01-01'), (2, '2099-01-01')");
        $model = $this->model('AccessLog', 'access_logs');
        $file = tempnam(sys_get_temp_dir(), 'retention');
        try {
            file_put_contents($file, '<?php $config = ["MISP" => ["system_setting_db" => true]];');
            $result = LogRetentionService::runConfigured($model, $file, true);
            $this->assertSame(1, $result['affected']);
            $this->assertSame('complete', $result['status']);
            $this->assertSame(1, LogRetentionService::deleteAccessLogsBefore($model, new DateTimeImmutable('2026-01-01T00:00:00Z')));
            $this->assertSame([2], array_map('intval', $this->pdo->query("SELECT id FROM $table")->fetchAll(PDO::FETCH_COLUMN)));
        } finally {
            unlink($file);
        }
    }
}

class RetentionPostgresApp
{
    public static function uses($class, $package) {}
}

class RetentionPostgresAppModel {}

class RetentionPostgresModel
{
    public $alias;
    public $table;
    private $pdo;
    private $prefix;
    public function __construct($alias, $table, PDO $pdo, $prefix)
    {
        $this->alias = $alias;
        $this->table = $table;
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }
    public function getDataSource() { return $this; }
    public function getConnection(): PDO { return $this->pdo; }
    public function getSqlDialect() { return new SqlDialect($this); }
    public function name($column): string { return '"' . $column . '"'; }
    public function fullTableName($model): string { return $this->name($this->prefix . (is_object($model) ? $model->table : $model)); }
}
