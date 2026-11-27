<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionSweep.php';

class LogRetentionSweepTest extends TestCase
{
    private function executeSweep(array &$rows, array &$saved, int $cursor = 0, bool $dryRun = false, ?callable $canContinue = null): array
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
            static function ($cursor) use (&$saved) {
                $saved[] = $cursor;
            },
            $canContinue ?? static function () { return true; },
            $dryRun
        );
    }

    public function testSparseAndNonChronologicalRowsWithExactCutoff(): void
    {
        $rows = [1 => '2026-01-02', 3 => '2026-01-01', 7 => '2025-12-31', 12 => '2025-12-30', 13 => '2025-12-29'];
        $saved = [];
        $result = $this->executeSweep($rows, $saved);
        $this->assertSame([1, 3, 13], array_keys($rows));
        $this->assertSame([3, 9, 12], $saved);
        $this->assertSame(2, $result['affected']);
        $this->assertTrue($result['complete']);
    }

    public function testDryRunDoesNotChangeRowsOrCheckpoint(): void
    {
        $rows = [1 => '2025-12-31', 12 => '2026-01-01'];
        $original = $rows;
        $saved = [];
        $result = $this->executeSweep($rows, $saved, 0, true);
        $this->assertSame($original, $rows);
        $this->assertSame([], $saved);
        $this->assertSame(1, $result['affected']);
        $this->assertTrue($result['complete']);
    }

    public function testPolicyChangeStopsBeforeNextBatchAndAllowsResume(): void
    {
        $rows = [1 => '2025-12-31', 7 => '2025-12-31'];
        $saved = [];
        $result = $this->executeSweep($rows, $saved, 0, false, static function () use (&$saved) { return !$saved; });
        $this->assertFalse($result['complete']);
        $this->assertSame(3, $result['cursor']);
        $this->assertSame([7], array_keys($rows));
        $result = $this->executeSweep($rows, $saved, $result['cursor']);
        $this->assertTrue($result['complete']);
        $this->assertSame([], $rows);
    }

    public function testBatchFailureDoesNotAdvanceCursor(): void
    {
        $saved = [];
        try {
            (new LogRetentionSweep())->run(0, 10, new DateTimeImmutable(), static function () { return 1; }, static function () {
                throw new RuntimeException('Database failure');
            }, static function ($cursor) use (&$saved) { $saved[] = $cursor; }, static function () { return true; });
            $this->fail('Expected database failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Database failure', $e->getMessage());
        }
        $this->assertSame([], $saved);
    }

    public function testEmptyRemainingRangeCompletesSweep(): void
    {
        $rows = [];
        $saved = [];
        $result = $this->executeSweep($rows, $saved);
        $this->assertTrue($result['complete']);
        $this->assertSame([12], $saved);
        $this->assertSame(0, $result['batches']);
    }

    public function testInvalidBatchResultCannotAdvanceCursor(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new LogRetentionSweep(3))->run(0, 10, new DateTimeImmutable(), static function () { return 1; }, static function () { return false; }, function () { $this->fail('Checkpoint advanced'); }, static function () { return true; });
    }
}
