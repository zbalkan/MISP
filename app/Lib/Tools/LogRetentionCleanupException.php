<?php

declare(strict_types=1);

/** Preserve both the operation failure and a subsequent cleanup failure. */
final class LogRetentionCleanupException extends RuntimeException
{
    private $cleanupFailure;

    public function __construct(Throwable $operationFailure, Throwable $cleanupFailure)
    {
        parent::__construct('Retention operation and cleanup both failed; inspect both exceptions.', 0, $operationFailure);
        $this->cleanupFailure = $cleanupFailure;
    }

    public function getCleanupFailure(): Throwable
    {
        return $this->cleanupFailure;
    }
}
