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

    public function __construct($model)
    {
        $tables = ['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'];
        if (!isset($tables[$model->alias]) || $model->table !== $tables[$model->alias]) {
            throw new InvalidArgumentException('Unsupported retention model.');
        }
        $db = $model->getDataSource();
        $this->connection = $db->getConnection();
        $this->table = $db->fullTableName($model);
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
