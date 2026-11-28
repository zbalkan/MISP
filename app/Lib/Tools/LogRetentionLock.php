<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionTopology.php';

/** Session-scoped advisory lock, independent of deletion and checkpoints. */
final class LogRetentionLock
{
    private $connection;
    private $driver;
    private $pgLockKeys;
    private $pgLockOids;
    private $lockName;
    private $locked = false;
    private $unusable = false;

    public function __construct($model, bool $provisioning = false)
    {
        $db = $model->getDataSource();
        $this->connection = $db->getConnection();
        $this->driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported retention database driver.');
        }
        if ((int)$this->connection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('Retention requires PDO exception mode.');
        }
        if ($this->connection->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new RuntimeException('Retention requires a non-persistent database session.');
        }
        if ($this->driver === 'mysql' && !$this->connection->getAttribute(PDO::ATTR_AUTOCOMMIT)) {
            throw new RuntimeException('Retention requires an autocommit database session.');
        }
        $database = $this->scalar($this->driver === 'mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()', []);
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('Retention database name unavailable.');
        }
        $scope = $provisioning ? ':provisioning' : '';
        $this->lockName = 'misp:retention:' . substr(hash('sha256', $database . ':' . $db->fullTableName($provisioning ? 'access_logs' : $model, false, false) . $scope), 0, 48);
        if ($this->driver === 'pgsql') {
            // Advisory locks are database-local. Use the relation OID to
            // identify the same table even through different search paths.
            $relation = $provisioning ? $db->fullTableName('scheduled_tasks') : $db->fullTableName($model);
            $oid = $this->scalar('SELECT CAST(to_regclass(?) AS oid)', [$relation]);
            if ($oid === null || $oid === false) {
                throw new RuntimeException('Retention table is unavailable.');
            }
            $this->pgLockOids = array_values(unpack('N2', hash('sha256', 'misp:retention:' . $oid . $scope, true)));
            $this->pgLockKeys = array_map(static function ($value) {
                return $value > 2147483647 ? $value - 4294967296 : $value;
            }, $this->pgLockOids);
        }
    }

    public function acquireLock(): bool
    {
        if ($this->unusable || $this->connection->inTransaction()) {
            throw new RuntimeException('Retention requires a usable session with no active transaction.');
        }
        if ($this->locked) {
            throw new LogicException('Retention lock already acquired.');
        }
        if ($this->driver === 'pgsql') {
            if ((int)$this->scalar('SELECT CASE WHEN pg_is_in_recovery() THEN 1 ELSE 0 END', []) === 1 ||
                (int)$this->scalar("SELECT COUNT(*) FROM pg_extension WHERE extname IN ('bdr', 'pglogical')", []) > 0) {
                throw new RuntimeException('Retention requires a single writable PostgreSQL server.');
            }
            if ($this->ownsPostgresLock()) {
                return false;
            }
            $this->locked = (int)$this->scalar(
                'SELECT CASE WHEN pg_try_advisory_lock(CAST(? AS integer), CAST(? AS integer)) THEN 1 ELSE 0 END',
                $this->pgLockKeys
            ) === 1;
            return $this->locked;
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
        if ($this->driver === 'pgsql') {
            return $this->ownsPostgresLock();
        }
        $result = $this->scalar('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', [$this->lockName]);
        return $result === 1 || $result === '1';
    }

    private function ownsPostgresLock(): bool
    {
        return (int)$this->scalar("SELECT COUNT(*) FROM pg_locks
            WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted
            AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
            AND classid = CAST(? AS oid) AND objid = CAST(? AS oid) AND objsubid = 2", $this->pgLockOids) === 1;
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
            $result = $this->driver === 'pgsql'
                ? $this->scalar('SELECT CASE WHEN pg_advisory_unlock(CAST(? AS integer), CAST(? AS integer)) THEN 1 ELSE 0 END', $this->pgLockKeys)
                : $this->scalar('SELECT RELEASE_LOCK(?)', [$this->lockName]);
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
