<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionSweep.php';

class LogRetentionSweepTest extends TestCase
{
    private function executeSweep(array &$rows, int $cursor = 0, bool $dryRun = false, ?callable $canContinue = null): array
    {
        $cutoff = new DateTimeImmutable('2026-01-01 00:00:00');
        return (new LogRetentionSweep(3))->run(
            $cursor,
            12,
            $cutoff,
            static function ($cursor, $high) use (&$rows) {
                foreach (array_keys($rows) as $id) {
                    if ($id > $cursor && $id <= $high) {
                        return $id;
                    }
                }
                return null;
            },
            static function ($first, $last, $cutoff, $dryRun) use (&$rows) {
                $count = 0;
                foreach ($rows as $id => $created) {
                    if ($id >= $first && $id <= $last && new DateTimeImmutable($created) < $cutoff) {
                        ++$count;
                        if (!$dryRun) {
                            unset($rows[$id]);
                        }
                    }
                }
                return $count;
            },
            $canContinue ?? static function () { return true; },
            $dryRun
        );
    }

    public function testSparseAndNonChronologicalRowsWithExactCutoff(): void
    {
        $rows = [1 => '2026-01-02', 3 => '2026-01-01', 7 => '2025-12-31', 12 => '2025-12-30', 13 => '2025-12-29'];
        $result = $this->executeSweep($rows);
        $this->assertSame([1, 3, 13], array_keys($rows));
        $this->assertSame(2, $result['affected']);
        $this->assertTrue($result['complete']);
    }

    public function testDryRunDoesNotChangeRows(): void
    {
        $rows = [1 => '2025-12-31', 12 => '2026-01-01'];
        $original = $rows;
        $result = $this->executeSweep($rows, 0, true);
        $this->assertSame($original, $rows);
        $this->assertSame(1, $result['affected']);
        $this->assertTrue($result['complete']);
    }

    public function testPolicyChangeStopsBeforeNextBatchAndAllowsRetry(): void
    {
        $rows = [1 => '2025-12-31', 7 => '2025-12-31'];
        $result = $this->executeSweep($rows, 0, false, static function () use (&$rows) { return isset($rows[1]); });
        $this->assertFalse($result['complete']);
        $this->assertSame(3, $result['cursor']);
        $this->assertSame([7], array_keys($rows));
        $result = $this->executeSweep($rows);
        $this->assertTrue($result['complete']);
        $this->assertSame([], $rows);
    }

    public function testBatchFailureDoesNotAdvanceCursor(): void
    {
        try {
            (new LogRetentionSweep())->run(0, 10, new DateTimeImmutable(), static function () { return 1; }, static function () {
                throw new RuntimeException('Database failure');
            }, static function () { return true; });
            $this->fail('Expected database failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Database failure', $e->getMessage());
        }
    }

    public function testEmptyRemainingRangeCompletesSweep(): void
    {
        $rows = [];
        $result = $this->executeSweep($rows);
        $this->assertTrue($result['complete']);
        $this->assertSame(0, $result['batches']);
    }

    public function testMaximumBatchSizeIsAccepted(): void
    {
        $result = (new LogRetentionSweep(LogRetentionSweep::MAX_BATCH_SIZE))->run(
            0, 50001, new DateTimeImmutable(),
            static function ($cursor) { return $cursor + 1; },
            function ($first, $last) {
                $this->assertLessThanOrEqual(LogRetentionSweep::MAX_BATCH_SIZE, $last - $first + 1);
                return 0;
            },
            static function () { return true; }
        );
        $this->assertSame(2, $result['batches']);
        $this->assertTrue($result['complete']);
    }

    public function testOversizedBatchIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LogRetentionSweep(LogRetentionSweep::MAX_BATCH_SIZE + 1);
    }

    public function testPolicyChangeAfterNextIdPreventsBatch(): void
    {
        $allowed = true;
        $result = (new LogRetentionSweep())->run(
            0, 10, new DateTimeImmutable(),
            static function () use (&$allowed) { $allowed = false; return 1; },
            function () { $this->fail('Batch executed after policy change.'); },
            static function () use (&$allowed) { return $allowed; }
        );
        $this->assertSame(0, $result['cursor']);
        $this->assertFalse($result['complete']);
    }

    public function testInvalidBatchResultCannotAdvanceCursor(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new LogRetentionSweep(3))->run(0, 10, new DateTimeImmutable(), static function () { return 1; }, static function () { return false; }, static function () { return true; });
    }
}
