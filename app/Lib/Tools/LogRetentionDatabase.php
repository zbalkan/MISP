<?php

declare(strict_types=1);

/**
 * Database operations for the three supported log tables.
 * Use the same PDO session for advisory locking and deletion.
 */
final class LogRetentionDatabase
{
    private $connection;
    private $table;
    private $lockName;
    private $locked = false;

    public function __construct($model)
    {
        $tables = ['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'];
        if (!isset($tables[$model->alias]) || $model->table !== $tables[$model->alias]) {
            throw new InvalidArgumentException('Unsupported retention model.');
        }
        $db = $model->getDataSource();
        $this->connection = $db->getConnection();
        $this->table = $db->fullTableName($model);
        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Retention locking is currently supported only on MySQL and MariaDB.');
        }
        if ($this->connection->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new RuntimeException('Retention requires a non-persistent database session.');
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

    public function highWaterMark(): int
    {
        return (int)$this->scalar("SELECT MAX(id) FROM {$this->table}", []);
    }

    public function nextId(int $cursor, int $highWaterMark): ?int
    {
        $id = $this->scalar("SELECT id FROM {$this->table} WHERE id > ? AND id <= ? ORDER BY id ASC LIMIT 1", [$cursor, $highWaterMark]);
        return $id === false ? null : (int)$id;
    }

    public function processBatch(int $first, int $last, DateTimeImmutable $cutoff, bool $dryRun): int
    {
        if ($first < 1 || $last < $first || $last - $first >= 10000) {
            throw new InvalidArgumentException('Invalid retention batch bounds.');
        }
        $where = " WHERE id >= ? AND id <= ? AND created < ?";
        $params = [$first, $last, $cutoff->format('Y-m-d H:i:s.u')];
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
