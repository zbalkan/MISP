<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionSweep.php';

/**
 * Database operations for the three supported log tables.
 * Use the same PDO session for advisory locking and deletion.
 */
final class LogRetentionDatabase
{
    private $connection;
    private $storageTimezone;
    private $table;
    private $lockName;
    private $checkpointTable;
    private $settingsTable;
    private $checkpointKey;
    private $locked = false;

    /**
     * Local-time log tables require the established writer timezone, not the
     * timezone of the CLI process. AccessLog timestamps are always UTC.
     */
    public function __construct($model, ?DateTimeZone $logTimezone = null)
    {
        $tables = ['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'];
        if (!isset($tables[$model->alias]) || $model->table !== $tables[$model->alias]) {
            throw new InvalidArgumentException('Unsupported retention model.');
        }
        if ($model->alias !== 'AccessLog' && $logTimezone === null) {
            throw new InvalidArgumentException('The log writer timezone must be explicitly established.');
        }
        $this->storageTimezone = $model->alias === 'AccessLog' ? new DateTimeZone('UTC') : $logTimezone;
        $db = $model->getDataSource();
        $this->connection = $db->getConnection();
        $this->table = $db->fullTableName($model);
        $this->checkpointTable = $db->fullTableName('admin_settings');
        $this->settingsTable = $db->fullTableName('system_settings');
        $this->checkpointKey = 'log_retention_checkpoint_' . $model->table;
        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Retention locking is currently supported only on MySQL and MariaDB.');
        }
        if ($this->connection->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new RuntimeException('Retention requires a non-persistent database session.');
        }
        if (!$this->connection->getAttribute(PDO::ATTR_AUTOCOMMIT)) {
            throw new RuntimeException('Retention requires an autocommit database session.');
        }
        $database = $this->scalar('SELECT DATABASE()', []);
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('Retention database name unavailable.');
        }
        $this->lockName = 'misp:retention:' . substr(hash('sha256', $database . ':' . $this->table), 0, 48);
    }

    public function acquireLock(): bool
    {
        if ($this->locked) {
            throw new LogicException('Retention lock already acquired.');
        }
        // MySQL named locks are recursive within one session. Do not allow
        // a second adapter sharing the PDO connection to acquire the same lock.
        if ($this->scalar('SELECT IS_USED_LOCK(?)', [$this->lockName]) !== null) {
            return false;
        }
        $result = $this->scalar('SELECT GET_LOCK(?, 0)', [$this->lockName]);
        if ($result === 0 || $result === '0') {
            return false;
        }
        if ($result !== 1 && $result !== '1') {
            throw new RuntimeException('Could not acquire retention lock.');
        }
        $this->locked = true;
        return true;
    }

    public function ownsLock(): bool
    {
        if (!$this->locked) {
            return false;
        }
        $result = $this->scalar('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', [$this->lockName]);
        return $result === 1 || $result === '1';
    }

    public function releaseLock(): void
    {
        if (!$this->locked) {
            return;
        }
        $this->locked = false;
        $result = $this->scalar('SELECT RELEASE_LOCK(?)', [$this->lockName]);
        if ($result !== 1 && $result !== '1') {
            throw new RuntimeException('Could not release retention lock.');
        }
    }

    public function readPolicySetting(string $setting): ?string
    {
        $value = $this->scalar("SELECT value FROM {$this->settingsTable} WHERE setting = ?", [$setting]);
        return $value === false ? null : (string)$value;
    }

    public function loadCheckpoint(): ?string
    {
        if (!$this->ownsLock()) {
            throw new RuntimeException('Retention checkpoint requires an owned database lock.');
        }
        $value = $this->scalar("SELECT value FROM {$this->checkpointTable} WHERE setting = ?", [$this->checkpointKey]);
        return $value === false ? null : (string)$value;
    }

    public function saveCheckpoint(string $value): void
    {
        if (!$this->ownsLock() || $this->connection->inTransaction()) {
            throw new RuntimeException('Retention checkpoint requires an owned lock and no active transaction.');
        }
        $statement = $this->execute(
            "INSERT INTO {$this->checkpointTable} (setting, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
            [$this->checkpointKey, $value, $value]
        );
        $statement->closeCursor();
    }

    public function highWaterMark(): int
    {
        return (int)$this->scalar("SELECT MAX(id) FROM {$this->table}", []);
    }

    public function nextId(int $cursor, int $highWaterMark): ?int
    {
        $id = $this->scalar("SELECT id FROM {$this->table} WHERE id > ? AND id <= ? ORDER BY id ASC LIMIT 1", [$cursor, $highWaterMark]);
        return $id === false ? null : (int)$id;
    }

    public function storageTimezone(): DateTimeZone
    {
        return $this->storageTimezone;
    }

    /**
     * DATETIME has no offset. During a backward clock transition, retain the
     * entire repeated wall-clock interval until both occurrences have expired.
     */
    private function storageCutoff(DateTimeImmutable $cutoff): string
    {
        $local = $cutoff->setTimezone($this->storageTimezone);
        $timestamp = $local->getTimestamp();
        $transitions = $this->storageTimezone->getTransitions($timestamp - 172800, $timestamp + 172800);
        if ($transitions !== false) {
            $previousOffset = $transitions[0]['offset'];
            foreach (array_slice($transitions, 1) as $transition) {
                $offset = $transition['offset'];
                $repeatedSeconds = $previousOffset - $offset;
                $at = $transition['ts'];
                if ($repeatedSeconds > 0 && $timestamp >= $at - $repeatedSeconds && $timestamp < $at + $repeatedSeconds) {
                    return gmdate('Y-m-d H:i:s', $at + $offset) . '.000000';
                }
                $previousOffset = $offset;
            }
        }
        return $local->format('Y-m-d H:i:s.u');
    }

    public function processBatch(int $first, int $last, DateTimeImmutable $cutoff, bool $dryRun): int
    {
        if ($first < 1 || $last < $first || $last - $first >= LogRetentionSweep::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('Invalid retention batch bounds.');
        }
        $where = " WHERE id >= ? AND id <= ? AND created < ?";
        $params = [$first, $last, $this->storageCutoff($cutoff)];
        if ($dryRun) {
            return (int)$this->scalar("SELECT COUNT(*) FROM {$this->table}" . $where, $params);
        }
        if (!$this->ownsLock()) {
            throw new RuntimeException('Retention deletion requires an owned database lock.');
        }
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Retention requires its own transaction.');
        }
        if (!$this->connection->beginTransaction()) {
            throw new RuntimeException('Could not begin retention transaction.');
        }
        try {
            $statement = $this->execute("DELETE FROM {$this->table}" . $where, $params);
            $count = $statement->rowCount();
            $statement->closeCursor();
            if ($count < 0 || $count > $last - $first + 1) {
                throw new RuntimeException('Unexpected retention deletion count.');
            }
            if (!$this->connection->commit()) {
                throw new RuntimeException('Could not commit retention transaction.');
            }
            return $count;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    private function scalar(string $sql, array $params)
    {
        $statement = $this->execute($sql, $params);
        try {
            return $statement->fetchColumn();
        } finally {
            $statement->closeCursor();
        }
    }

    private function execute(string $sql, array $params): PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        if (!$statement || !$statement->execute($params)) {
            throw new RuntimeException('Retention database query failed.');
        }
        return $statement;
    }
}
