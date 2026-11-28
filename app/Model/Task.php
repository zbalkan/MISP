<?php
App::uses('AppModel', 'Model');
App::uses('Job', 'Model');

class Task extends AppModel
{
    const RETENTION_ACTION = 'enforceLogRetention';
    const RETENTION_INTERVAL = 86400;

    public $recursive = -1;

    public $useTable = 'scheduled_tasks';

    public $actsAs = array(
        'Containable',
    );

    public $belongsTo = [
        'User',
        'Job' => [
            'className' => 'Job',
            'foreignKey' => 'last_job_id',
        ]
    ];

    public static function isManagedRetention(array $task): bool
    {
        return ($task['type'] ?? null) === 'Admin' && ($task['action'] ?? null) === self::RETENTION_ACTION;
    }

    /** Explain a blocked claim using the current task snapshot, not a recovery decision. */
    public static function retentionBlockReason(array $task, ?array $job, int $now): ?string
    {
        if (!self::isManagedRetention($task) || empty($task['enabled']) ||
            (int)$task['next_execution_time'] > $now) {
            return null;
        }
        if ((int)$task['user_id'] !== 0 || (int)$task['timer'] !== self::RETENTION_INTERVAL) {
            return 'Invalid managed retention task owner or interval.';
        }
        if (empty($task['last_job_id'])) {
            return null;
        }
        $id = (int)$task['last_job_id'];
        if (!$job || (int)($job['id'] ?? 0) !== $id || !isset($job['status'])) {
            return "Managed retention task blocked by missing job $id.";
        }
        $status = (int)$job['status'];
        if (in_array($status, [Job::STATUS_FAILED, Job::STATUS_COMPLETED], true)) {
            return null;
        }
        if (in_array($status, [Job::STATUS_CREATED, Job::STATUS_WAITING, Job::STATUS_RUNNING], true)) {
            return "Managed retention task blocked by nonterminal job $id (status $status).";
        }
        return "Managed retention task blocked by job $id with unexpected status $status.";
    }

    /** Caller serializes provisioning across scheduler sessions. */
    public function ensureRetentionTask(int $now): void
    {
        $tasks = $this->find('all', [
            'conditions' => ['Task.type' => 'Admin', 'Task.action' => self::RETENTION_ACTION],
            'recursive' => -1,
        ]);
        if (count($tasks) > 1) {
            throw new RuntimeException('Multiple managed retention tasks exist.');
        }
        if ($tasks) {
            return;
        }
        $this->create();
        if (!$this->save([
            'type' => 'Admin', 'action' => self::RETENTION_ACTION,
            'description' => 'Managed database log retention (configure via MISP settings)',
            'timer' => self::RETENTION_INTERVAL, 'next_execution_time' => $now,
            'enabled' => true, 'user_id' => 0, 'message' => '', 'params' => null,
        ], ['atomic' => false])) {
            throw new RuntimeException('Could not provision managed retention task.');
        }
    }

    /** Claim the native schedule, not a second timestamp in admin_settings.
     * An active or unknown previous job blocks another dispatch.
     */
    public function claimRetentionExecution(array $task, int $now): ?int
    {
        if (!self::isManagedRetention($task) || (int)$task['user_id'] !== 0 ||
            (int)$task['timer'] !== self::RETENTION_INTERVAL || (int)$task['next_execution_time'] > $now) {
            return null;
        }
        $db = $this->getDataSource();
        $pdo = $db->getConnection();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Task claim requires an autocommit session.');
        }
        $table = $db->fullTableName($this);
        $jobs = $db->fullTableName('jobs');
        // Fail closed if the managed schedule is not unique.
        $count = $this->find('count', [
            'conditions' => ['Task.type' => 'Admin', 'Task.action' => self::RETENTION_ACTION],
            'recursive' => -1,
        ]);
        if ((int)$count !== 1) {
            return null;
        }
        $next = $now + self::RETENTION_INTERVAL;
        $statement = $pdo->prepare("UPDATE $table SET next_execution_time = ?, last_job_id = NULL
            WHERE id = ? AND type = 'Admin' AND action = ? AND user_id = 0 AND enabled = TRUE
            AND timer = ? AND next_execution_time = ? AND next_execution_time <= ?
            AND (last_job_id IS NULL OR last_job_id IN (SELECT id FROM $jobs WHERE status IN (?, ?)))");
        try {
            $statement->execute([$next, $task['id'], self::RETENTION_ACTION, self::RETENTION_INTERVAL, $task['next_execution_time'], $now, Job::STATUS_FAILED, Job::STATUS_COMPLETED]);
            return $statement->rowCount() === 1 ? $next : null;
        } finally {
            $statement->closeCursor();
        }
    }

    /** Associate the job before enqueueing; reject a stale claim. */
    public function attachRetentionJob(int $id, int $claim, int $jobId): bool
    {
        $db = $this->getDataSource();
        $table = $db->fullTableName($this);
        $jobs = $db->fullTableName('jobs');
        $statement = $db->getConnection()->prepare("UPDATE $table SET last_job_id = ?, message = ?, last_run_at = ?
            WHERE id = ? AND type = 'Admin' AND action = ? AND user_id = 0 AND enabled = TRUE
            AND next_execution_time = ? AND last_job_id IS NULL
            AND EXISTS (SELECT 1 FROM $jobs WHERE id = ? AND status = ?)");
        try {
            $statement->execute([$jobId, 'Dispatch reserved', time(), $id, self::RETENTION_ACTION, $claim, $jobId, Job::STATUS_CREATED]);
            return $statement->rowCount() === 1;
        } finally {
            $statement->closeCursor();
        }
    }
}
