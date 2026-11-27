<?php

declare(strict_types=1);

/** Background execution and reporting, using the same service as the CLI. */
final class LogRetentionScheduledJob
{
    public static function run(callable $runTable, callable $report): bool
    {
        $success = true;
        foreach (['AuditLog', 'AccessLog', 'Log'] as $index => $alias) {
            $report("$alias: starting configured retention", (int) floor($index * 100 / 3));
            try {
                $result = $runTable($alias);
            } catch (Throwable $e) {
                $report("$alias: " . get_class($e) . ': ' . $e->getMessage(), (int) floor($index * 100 / 3));
                // Do not reuse a potentially uncertain database session.
                return false;
            }
            $report(sprintf(
                '%s: status=%s days=%s cutoff=%s batches=%s deleted=%s duration=%.3f seconds reason=%s',
                $alias, $result['status'], $result['days'], $result['cutoff'] ?? '-',
                $result['batches'], $result['affected'], $result['durationSeconds'], $result['reason'] ?? '-'
            ), min(99, (int) floor(($index + 1) * 100 / 3)));
            if (!in_array($result['status'], ['complete', 'disabled'], true)) {
                $success = false;
            }
        }
        return $success;
    }
}
