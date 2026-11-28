<?php

declare(strict_types=1);

/**
 * Traverse a snapshot of a log table's primary-key space in bounded batches.
 * The caller owns the database lock, cutoff and execution policy.
 */
final class LogRetentionSweep
{
    public const DEFAULT_BATCH_SIZE = 10000;
    public const MAX_BATCH_SIZE = 50000;

    private $batchSize;

    public function __construct(int $batchSize = self::DEFAULT_BATCH_SIZE)
    {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('Batch size must be between 1 and ' . self::MAX_BATCH_SIZE . '.');
        }
        $this->batchSize = $batchSize;
    }

    /**
     * $nextId($cursor, $highWaterMark) returns the first existing ID strictly
     * after the cursor, or null if none exists. It must throw on query failure.
     * $processBatch($first, $last, $cutoff, $dryRun) counts or deletes rows
     * strictly older than the cutoff, and returns their count only on success.
     * A deletion must be committed before this callback returns.
     * $canContinue() verifies lock ownership, current policy and execution budget.
     */
    public function run(
        int $cursor,
        int $highWaterMark,
        DateTimeImmutable $cutoff,
        callable $nextId,
        callable $processBatch,
        callable $canContinue,
        bool $dryRun = false
    ): array {
        if ($cursor < 0 || $highWaterMark < $cursor) {
            throw new InvalidArgumentException('Invalid retention sweep bounds.');
        }
        $affected = 0;
        $batches = 0;
        while ($cursor < $highWaterMark && $canContinue()) {
            $first = $nextId($cursor, $highWaterMark);
            if (!$canContinue()) {
                break;
            }
            if ($first === null) {
                $cursor = $highWaterMark;
                break;
            }
            if (!is_int($first) || $first <= $cursor || $first > $highWaterMark) {
                throw new UnexpectedValueException('Invalid retention batch start.');
            }
            // Subtract before adding to avoid overflowing at the high-water mark.
            $last = $first + min($this->batchSize - 1, $highWaterMark - $first);
            $count = $processBatch($first, $last, $cutoff, $dryRun);
            if (!is_int($count) || $count < 0 || $count > $last - $first + 1) {
                throw new UnexpectedValueException('Invalid retention batch result.');
            }
            $cursor = $last;
            $affected += $count;
            ++$batches;
        }
        return [
            'cursor' => $cursor,
            'highWaterMark' => $highWaterMark,
            'affected' => $affected,
            'batches' => $batches,
            'complete' => $cursor === $highWaterMark,
            'dryRun' => $dryRun,
        ];
    }
}
