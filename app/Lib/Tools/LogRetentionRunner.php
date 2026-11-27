<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionPolicy.php';
require_once __DIR__ . '/LogRetentionCheckpoint.php';
require_once __DIR__ . '/LogRetentionSweep.php';
require_once __DIR__ . '/LogRetentionCleanupException.php';

/**
 * Orchestrates a single table sweep. The policy reader must read current
 * configuration, not a cached Configure value.
 */
final class LogRetentionRunner
{
    public function run($database, callable $readPolicy, bool $dryRun = false, int $runtimeSeconds = 1800, int $delayMicroseconds = 200000): array
    {
        if ($runtimeSeconds <= 0 || $delayMicroseconds < 0) {
            throw new InvalidArgumentException('Invalid retention execution limits.');
        }
        $days = LogRetentionPolicy::parse($readPolicy());
        if ($days === 0) {
            return ['status' => 'disabled', 'affected' => 0, 'dryRun' => $dryRun];
        }
        if (!$database->acquireLock()) {
            return ['status' => 'busy', 'affected' => 0, 'dryRun' => $dryRun];
        }
        $failure = null;
        try {
            $started = hrtime(true);
            $timezone = $database->storageTimezone();
            $cutoff = LogRetentionPolicy::cutoff($days, new DateTimeImmutable('now', $timezone));
            $high = $database->highWaterMark();
            $state = $dryRun ? null : LogRetentionCheckpoint::resume($database->loadCheckpoint(), $days, $cutoff, $high);
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
                function ($cursor) use ($database, $days, $cutoff, $high) {
                    $database->saveCheckpoint(LogRetentionCheckpoint::encode($days, $cutoff, $cursor, $high));
                },
                $canContinue,
                $dryRun
            );
            $result['status'] = $result['complete'] ? 'complete' : 'partial';
            $result['reason'] = $reason;
            return $result;
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
