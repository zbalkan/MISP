<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionDatabase.php';
require_once __DIR__ . '/LogRetentionRunner.php';

/** Shared entry point for the supported MISP log models. */
final class LogRetentionService
{
    public static function deleteAccessLogsBefore($model, DateTimeImmutable $cutoff): int
    {
        if ($model->alias !== 'AccessLog') {
            throw new InvalidArgumentException('Expected the access log model.');
        }
        return (new LogRetentionRunner())->runFixedCutoff(new LogRetentionDatabase($model), $cutoff);
    }

    /**
     * The reader receives the adapter so configured policies can be refreshed
     * from the same database session. Local-time tables require the known
     * writer timezone. Explicit one-time operations must not share scheduled
     * checkpoints.
     */
    public static function run($model, callable $readPolicy, bool $dryRun = false, ?DateTimeZone $logTimezone = null, bool $persistCheckpoint = true): array
    {
        $database = new LogRetentionDatabase($model, $logTimezone);
        return (new LogRetentionRunner())->run(
            $database,
            static function () use ($readPolicy, $database) { return $readPolicy($database); },
            $dryRun,
            1800,
            200000,
            $persistCheckpoint
        );
    }
}
