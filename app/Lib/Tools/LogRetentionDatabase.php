<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionSweep.php';
require_once __DIR__ . '/LogRetentionCleanupException.php';
require_once __DIR__ . '/LogRetentionTopology.php';

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
    private $unusable = false;

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
        if ((int)$this->connection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('Retention requires PDO exception mode.');
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
        if ($this->unusable || $this->connection->inTransaction()) {
            throw new RuntimeException('Retention requires a usable session with no active transaction.');
        }
        if ($this->locked) {
            throw new LogicException('Retention lock already acquired.');
        }
        $statement = $this->execute(
            "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('wsrep_on', 'wsrep_provider', 'group_replication_group_name')",
            []
        );
        try {
            LogRetentionTopology::assertSupported($statement->fetchAll(PDO::FETCH_KEY_PAIR));
        } finally {
            $statement->closeCursor();
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
        if (!$this->locked || $this->unusable) {
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
        try {
            if ($this->unusable || $this->connection->inTransaction()) {
                throw new RuntimeException('Retention session must be closed after an uncertain transaction outcome.');
            }
            $result = $this->scalar('SELECT RELEASE_LOCK(?)', [$this->lockName]);
            if ($result !== 1 && $result !== '1') {
                throw new RuntimeException('Could not release retention lock.');
            }
            $this->locked = false;
        } catch (Throwable $e) {
            // Do not reuse a session whose cleanup outcome is uncertain.
            $this->unusable = true;
            throw $e;
        }
    }

    /** Reserve a dispatch interval before enqueueing, using database time.
     * The unique setting key serializes competing scheduler processes.
     */
    public function claimDailyDispatch(): bool
    {
        if ($this->unusable || $this->connection->inTransaction()) {
            throw new RuntimeException('Retention dispatch requires an autocommit session.');
        }
        $key = 'log_retention_next_dispatch';
        $statement = $this->execute(
            "INSERT INTO {$this->checkpointTable} (setting, value) VALUES (?, '0') ON DUPLICATE KEY UPDATE setting = setting",
            [$key]
        );
        $statement->closeCursor();
        $statement = $this->execute(
            "UPDATE {$this->checkpointTable} SET value = UNIX_TIMESTAMP() + 86400 WHERE setting = ? AND value REGEXP '^[0-9]{1,12}(string $setting): ?string
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
        if ($this->storageTimezone === null) {
            throw new InvalidArgumentException('The log writer timezone must be explicitly established.');
        }
        return $this->storageTimezone;
    }

    /**
     * DATETIME has no offset. During a backward clock transition, retain the
     * entire repeated wall-clock interval until both occurrences have expired.
     */
    public function storageCutoff(DateTimeImmutable $cutoff): string
    {
        $timezone = $this->storageTimezone();
        $local = $cutoff->setTimezone($timezone);
        $timestamp = $local->getTimestamp();
        $transitions = $timezone->getTransitions($timestamp - 172800, $timestamp + 172800);
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
        if ($this->unusable) {
            throw new RuntimeException('Retention session is unusable.');
        }
        if (!$this->ownsLock()) {
            throw new RuntimeException('Retention batch requires an owned database lock.');
        }
        if ($dryRun) {
            return (int)$this->scalar("SELECT COUNT(*) FROM {$this->table}" . $where, $params);
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
            try {
                if ($this->connection->inTransaction() && !$this->connection->rollBack()) {
                    throw new RuntimeException('Could not roll back retention transaction.');
                }
            } catch (Throwable $cleanupFailure) {
                $this->unusable = true;
                throw new LogRetentionCleanupException($e, $cleanupFailure);
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
 AND CAST(value AS UNSIGNED) <= UNIX_TIMESTAMP()",
            [$key]
        );
        try {
            return $statement->rowCount() === 1;
        } finally {
            $statement->closeCursor();
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
        if ($this->storageTimezone === null) {
            throw new InvalidArgumentException('The log writer timezone must be explicitly established.');
        }
        return $this->storageTimezone;
    }

    /**
     * DATETIME has no offset. During a backward clock transition, retain the
     * entire repeated wall-clock interval until both occurrences have expired.
     */
    public function storageCutoff(DateTimeImmutable $cutoff): string
    {
        $timezone = $this->storageTimezone();
        $local = $cutoff->setTimezone($timezone);
        $timestamp = $local->getTimestamp();
        $transitions = $timezone->getTransitions($timestamp - 172800, $timestamp + 172800);
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
        if ($this->unusable) {
            throw new RuntimeException('Retention session is unusable.');
        }
        if (!$this->ownsLock()) {
            throw new RuntimeException('Retention batch requires an owned database lock.');
        }
        if ($dryRun) {
            return (int)$this->scalar("SELECT COUNT(*) FROM {$this->table}" . $where, $params);
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
            try {
                if ($this->connection->inTransaction() && !$this->connection->rollBack()) {
                    throw new RuntimeException('Could not roll back retention transaction.');
                }
            } catch (Throwable $cleanupFailure) {
                $this->unusable = true;
                throw new LogRetentionCleanupException($e, $cleanupFailure);
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
