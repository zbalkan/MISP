<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionPolicy.php';
require_once __DIR__ . '/LogRetentionCheckpoint.php';
require_once __DIR__ . '/LogRetentionSweep.php';
require_once __DIR__ . '/LogRetentionCleanupException.php';

/**
 * Orchestrates a single table sweep. The policy reader must read current
 * configuration, not a cached Configure value.
 * Dry runs start from zero and never persist or resume a checkpoint. A partial
 * dry run reports only the examined range, not a total count for the table.
 * Even a complete dry run is not a transactional snapshot of concurrent writes.
 */
final class LogRetentionRunner
{
    public function run($database, callable $readPolicy, bool $dryRun = false, int $runtimeSeconds = 1800, int $delayMicroseconds = 200000, bool $persistCheckpoint = true): array
    {
        if ($runtimeSeconds <= 0 || $delayMicroseconds < 0) {
            throw new InvalidArgumentException('Invalid retention execution limits.');
        }
        $started = hrtime(true);
        $days = LogRetentionPolicy::parse($readPolicy());
        if ($days === 0) {
            return ['status' => 'disabled', 'reason' => 'disabled', 'complete' => false, 'affected' => 0, 'batches' => 0, 'days' => $days, 'cutoff' => null, 'durationSeconds' => (hrtime(true) - $started) / 1e9, 'dryRun' => $dryRun];
        }
        if (!$database->acquireLock()) {
            return ['status' => 'busy', 'reason' => 'busy', 'complete' => false, 'affected' => 0, 'batches' => 0, 'days' => $days, 'cutoff' => null, 'durationSeconds' => (hrtime(true) - $started) / 1e9, 'dryRun' => $dryRun];
        }
        return $this->withOwnedLock($database, function () use ($database, $readPolicy, $days, $dryRun, $runtimeSeconds, $delayMicroseconds, $persistCheckpoint, $started) {
            $timezone = $database->storageTimezone();
            $cutoff = LogRetentionPolicy::cutoff($days, new DateTimeImmutable('now', $timezone));
            $high = $database->highWaterMark();
            $state = $dryRun || !$persistCheckpoint ? null : LogRetentionCheckpoint::resume($database->loadCheckpoint(), $days, $cutoff, $high);
            $cursor = $state['cursor'] ?? 0;
            $high = $state['highWaterMark'] ?? $high;
            $cutoff = $state['cutoff'] ?? $cutoff;
            $reason = null;
            $canContinue = function () use ($database, $readPolicy, $days, $cutoff, $timezone, $started, $runtimeSeconds, &$reason) {
                if (!$database->ownsLock()) {
                    throw new RuntimeException('Retention lock lost.');
                }
                if (LogRetentionPolicy::parse($readPolicy()) !== $days) {
                    $reason = 'policy_changed';
                    return false;
                }
                if (LogRetentionPolicy::cutoff($days, new DateTimeImmutable('now', $timezone)) < $cutoff) {
                    $reason = 'clock_changed';
                    return false;
                }
                if ((hrtime(true) - $started) / 1e9 >= $runtimeSeconds) {
                    $reason = 'runtime_limit';
                    return false;
                }
                return true;
            };
            $firstBatch = true;
            $result = (new LogRetentionSweep())->run(
                $cursor,
                $high,
                $cutoff,
                function ($cursor, $high) use ($database, $delayMicroseconds, &$firstBatch) {
                    if (!$firstBatch && $delayMicroseconds > 0) {
                        usleep($delayMicroseconds);
                    }
                    $firstBatch = false;
                    return $database->nextId($cursor, $high);
                },
                [$database, 'processBatch'],
                function ($cursor) use ($database, $days, $cutoff, $high, $persistCheckpoint) {
                    if ($persistCheckpoint) {
                        $database->saveCheckpoint(LogRetentionCheckpoint::encode($days, $cutoff, $cursor, $high));
                    }
                },
                $canContinue,
                $dryRun
            );
            $result['status'] = $result['complete'] ? 'complete' : 'partial';
            $result['reason'] = $reason;
            $result['days'] = $days;
            $result['cutoff'] = $cutoff;
            $result['durationSeconds'] = (hrtime(true) - $started) / 1e9;
            return $result;
        });
    }

    /**
     * Compatibility entry point for an explicit absolute cutoff. Preserve the
     * legacy full-sweep return contract without touching scheduled checkpoints.
     * Transactions are bounded; this manual operation has no sweep time limit.
     */
    public function runFixedCutoff($database, DateTimeImmutable $cutoff): int
    {
        return $this->runFixedCutoffResult($database, $cutoff)['affected'];
    }

    public function runFixedCutoffResult($database, DateTimeImmutable $cutoff, bool $dryRun = false): array
    {
        $started = hrtime(true);
        if ($cutoff > new DateTimeImmutable()) {
            throw new InvalidArgumentException('Retention cutoff must not be in the future.');
        }
        if (!$database->acquireLock()) {
            throw new RuntimeException('Another retention operation owns the database lock.');
        }
        return $this->withOwnedLock($database, function () use ($database, $cutoff, $dryRun, $started) {
            $firstBatch = true;
            $result = (new LogRetentionSweep())->run(
                0,
                $database->highWaterMark(),
                $cutoff,
                function ($cursor, $high) use ($database, &$firstBatch) {
                    if (!$firstBatch) {
                        usleep(200000);
                    }
                    $firstBatch = false;
                    return $database->nextId($cursor, $high);
                },
                [$database, 'processBatch'],
                static function () {},
                static function () use ($database, $cutoff) {
                    if (!$database->ownsLock()) {
                        throw new RuntimeException('Retention lock lost.');
                    }
                    if ($cutoff > new DateTimeImmutable()) {
                        throw new RuntimeException('System clock moved before the retention cutoff.');
                    }
                    return true;
                },
                $dryRun
            );
            $result['status'] = 'complete';
            $result['reason'] = null;
            $result['cutoff'] = $cutoff;
            $result['durationSeconds'] = (hrtime(true) - $started) / 1e9;
            return $result;
        });
    }

    private function withOwnedLock($database, callable $operation)
    {
        $failure = null;
        try {
            return $operation();
        } catch (Throwable $e) {
            $failure = $e;
            throw $e;
        } finally {
            try {
                $database->releaseLock();
            } catch (Throwable $cleanupFailure) {
                if ($failure !== null) {
                    throw new LogRetentionCleanupException($failure, $cleanupFailure);
                }
                throw $cleanupFailure;
            }
        }
    }
}
