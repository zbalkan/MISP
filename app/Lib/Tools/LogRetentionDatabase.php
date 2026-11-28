<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionSweep.php';
require_once __DIR__ . '/LogRetentionPolicy.php';
require_once __DIR__ . '/LogRetentionPolicyReader.php';
require_once __DIR__ . '/LogRetentionCleanupException.php';
require_once __DIR__ . '/LogRetentionTopology.php';

/**
 * Database operations for the three supported log tables.
 * Use the same PDO session for advisory locking and deletion.
 */
final class LogRetentionDatabase
{
    private $connection;
    private $dialect;
    private $driver;
    private $pgLockKeys;
    private $pgLockOids;
    private $mysqlDatabase;
    private $mysqlTables;
    private $storageTimezone;
    private $model;
    private $table;
    private $lockName;
    private $checkpointKey;
    private $locked = false;
    private $provisioning;
    private $unusable = false;

    /**
     * Local-time log tables require the established writer timezone, not the
     * timezone of the CLI process. AccessLog timestamps are always UTC.
     */
    public function __construct($model, ?DateTimeZone $logTimezone = null, bool $provisioning = false)
    {
        $this->provisioning = $provisioning;
        $this->model = $model;
        if ($provisioning && $model->alias !== 'AccessLog') {
            throw new InvalidArgumentException('Provisioning lock requires the AccessLog datasource.');
        }
        $tables = ['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'];
        if (!isset($tables[$model->alias]) || $model->table !== $tables[$model->alias]) {
            throw new InvalidArgumentException('Unsupported retention model.');
        }
        $this->storageTimezone = $model->alias === 'AccessLog' ? new DateTimeZone('UTC') : $logTimezone;
        $db = $model->getDataSource();
        $this->connection = $db->getConnection();
        $this->table = $db->fullTableName($model);
        $this->checkpointKey = 'log_retention_checkpoint_' . $model->table;
    }

    private function initialiseLock(): void
    {
        $this->assertSession();
        $model = $this->model;
        $db = $model->getDataSource();
        $provisioning = $this->provisioning;
        $this->driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported retention database driver.');
        }
        $this->dialect = $model->getSqlDialect();
        App::uses('AdminSetting', 'Model');
        if ($this->dialect->getDataSource() !== $db || $this->dialect->flavour() !== $this->driver) {
            throw new RuntimeException('Retention datasource dialect does not match its connection.');
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
        // Checkpoint keys are scoped to the datasource's standard table
        // prefix/schema. Do not allow a model-specific override to share them.
        if ($db->fullTableName($model, false, true) !== $db->fullTableName($model->table, false, true)) {
            throw new RuntimeException('Retention requires the datasource log table prefix and schema.');
        }
        if ($this->driver === 'mysql') {
            $this->mysqlDatabase = $database;
            $this->mysqlTables = [$db->fullTableName($model, false, false), $db->fullTableName('admin_settings', false, false)];
            foreach ([$model, 'admin_settings'] as $table) {
                $name = $db->fullTableName($table, false, false);
                $qualified = $db->fullTableName($table, false, true);
                if ($qualified !== $name && $qualified !== $database . '.' . $name) {
                    throw new RuntimeException('Retention requires log and checkpoint tables in the current database.');
                }
            }
        }
        $scope = $provisioning ? ':provisioning' : '';
        $this->lockName = 'misp:retention:' . substr(hash('sha256', $database . ':' . $db->fullTableName($model, false, false) . $scope), 0, 48);
        if ($this->driver === 'pgsql') {
            // Advisory locks are database-local. Use the relation OID to
            // identify the same table even through different search paths.
            $relation = $provisioning ? $db->fullTableName('scheduled_tasks') : $this->table;
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
        $this->initialiseLock();
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
        foreach ($this->provisioning ? [] : $this->mysqlTables as $table) {
            $statement = $this->execute('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$this->mysqlDatabase, $table]);
            try {
                $engines = $statement->fetchAll(PDO::FETCH_COLUMN);
            } finally {
                $statement->closeCursor();
            }
            if (count($engines) !== 1 || strcasecmp($engines[0] ?? '', 'InnoDB') !== 0) {
                throw new RuntimeException('Retention requires transactional InnoDB log and checkpoint tables.');
            }
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

    public function readPolicySetting(string $setting): ?string
    {
        $this->assertSession();
        return LogRetentionPolicyReader::readDatabaseSetting($this->model, $setting);
    }

    public function loadCheckpoint(): ?string
    {
        if ($this->provisioning) {
            throw new LogicException('Provisioning lock cannot authorise retention operations.');
        }
        if (!$this->ownsLock()) {
            throw new RuntimeException('Retention checkpoint requires an owned database lock.');
        }
        $this->assertSession();
        return AdminSetting::readInternalState($this->dialect, $this->checkpointKey);
    }

    public function saveCheckpoint(string $value): void
    {
        if ($this->provisioning) {
            throw new LogicException('Provisioning lock cannot authorise retention operations.');
        }
        if (!$this->ownsLock() || $this->connection->inTransaction()) {
            throw new RuntimeException('Retention checkpoint requires an owned lock and no active transaction.');
        }
        $this->assertSession();
        AdminSetting::writeInternalState($this->dialect, $this->checkpointKey, $value);
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
        if ($this->provisioning) {
            throw new LogicException('Provisioning lock cannot authorise retention operations.');
        }
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

    private function assertSession(): void
    {
        if ($this->model->getDataSource()->getConnection() !== $this->connection ||
            ($this->dialect !== null && $this->dialect->getDataSource()->getConnection() !== $this->connection)) {
            throw new RuntimeException('Retention datasource connection changed.');
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
