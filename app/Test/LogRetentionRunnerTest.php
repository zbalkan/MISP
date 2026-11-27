<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionRunner.php';

class LogRetentionRunnerTest extends TestCase
{
    public function testPolicyChangeStopsFurtherBatches(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $result = (new LogRetentionRunner())->run($database, function () use ($database) {
            return $database->batches === 0 ? 30 : 365;
        }, false, 1800, 0);
        $this->assertSame('partial', $result['status']);
        $this->assertSame('policy_changed', $result['reason']);
        $this->assertSame(1, $database->batches);
        $this->assertSame(10000, $result['cursor']);
        $this->assertFalse($database->locked);
    }

    public function testBlockedPolicyPreservesCommittedProgress(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $result = (new LogRetentionRunner())->run($database, static function () use ($database) {
            if ($database->batches > 0) {
                throw new LogRetentionConfigurationException('invalid policy');
            }
            return 30;
        }, false, 1800, 0);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid policy', $result['reason']);
        $this->assertSame(1, $result['affected']);
        $this->assertSame(10000, $result['cursor']);
        $this->assertFalse($database->locked);
        $this->assertNotNull($database->checkpoint);
    }

    public function testBlockedPolicyDoesNotMaskCleanupFailure(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $database->releaseFailure = new RuntimeException('release failed');
        $this->expectExceptionMessage('release failed');
        (new LogRetentionRunner())->run($database, static function () use ($database) {
            if ($database->batches > 0) {
                throw new LogRetentionConfigurationException('invalid policy');
            }
            return 30;
        }, false, 1800, 0);
    }

    public function testInjectedClockStopsSweepOnBackwardTimeChange(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $clock = new RetentionTestClock(new DateTimeImmutable('2026-03-01T12:00:00Z'));
        $result = (new LogRetentionRunner($clock))->run($database, static function () use ($database, $clock) {
            if ($database->batches > 0) {
                $clock->instant = new DateTimeImmutable('2026-02-28T12:00:00Z');
            }
            return 30;
        }, false, 1800, 0);
        $this->assertSame('partial', $result['status']);
        $this->assertSame('clock_changed', $result['reason']);
        $this->assertSame('2026-01-30T12:00:00+00:00', $result['cutoff']->format(DATE_ATOM));
        $this->assertSame(1, $database->batches);
        $this->assertFalse($database->locked);
    }

    public function testInjectedClockRejectsFutureExplicitCutoff(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $clock = new RetentionTestClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->expectException(InvalidArgumentException::class);
        (new LogRetentionRunner($clock))->runFixedCutoffResult($database, new DateTimeImmutable('2026-01-02T00:00:00Z'));
    }

    public function testDisabledDoesNotAcquireLock(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $result = (new LogRetentionRunner())->run($database, function () { return 0; });
        $this->assertSame('disabled', $result['status']);
        $this->assertSame(0, $database->acquisitions);
    }

    public function testOperationAndReleaseFailuresAreBothPreserved(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $database->batchFailure = new RuntimeException('batch failed');
        $database->releaseFailure = new RuntimeException('release failed');
        try {
            (new LogRetentionRunner())->run($database, static function () { return 30; }, false, 1800, 0);
            $this->fail('Expected cleanup failure.');
        } catch (LogRetentionCleanupException $e) {
            $this->assertSame($database->batchFailure, $e->getPrevious());
            $this->assertSame($database->releaseFailure, $e->getCleanupFailure());
            $this->assertNull($database->checkpoint);
        }
    }

    public function testReleaseFailureAloneFailsRun(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $database->releaseFailure = new RuntimeException('release failed');
        $this->expectExceptionMessage('release failed');
        (new LogRetentionRunner())->run($database, static function () { return 30; }, true, 1800, 0);
    }

    public function testPartialDryRunLeavesExistingCheckpointUntouched(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $database->checkpoint = 'existing checkpoint';
        $result = (new LogRetentionRunner())->run($database, static function () use ($database) {
            return $database->batches === 0 ? 30 : 365;
        }, true, 1800, 0);
        $this->assertSame('partial', $result['status']);
        $this->assertFalse($result['complete']);
        $this->assertSame('policy_changed', $result['reason']);
        $this->assertSame(1, $result['affected']);
        $this->assertSame(10000, $result['cursor']);
        $this->assertSame(20000, $result['highWaterMark']);
        $this->assertSame('existing checkpoint', $database->checkpoint);
        $this->assertFalse($database->locked);
    }

    public function testBusyDryRunDoesNotReportCompleteCount(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $database->busy = true;
        $result = (new LogRetentionRunner())->run($database, static function () { return 30; }, true);
        $this->assertSame('busy', $result['status']);
        $this->assertFalse($result['complete']);
        $this->assertSame(0, $database->batches);
    }

    public function testExplicitDryRunReportsCutoffWithoutCheckpointChanges(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $database->checkpoint = 'existing';
        $cutoff = new DateTimeImmutable('2025-01-01T00:00:00Z');
        $result = (new LogRetentionRunner())->runFixedCutoffResult($database, $cutoff, true);
        $this->assertSame('complete', $result['status']);
        $this->assertSame($cutoff, $result['cutoff']);
        $this->assertSame(2, $result['batches']);
        $this->assertTrue($result['dryRun']);
        $this->assertSame('existing', $database->checkpoint);
        $this->assertFalse($database->locked);
    }

    public function testDryRunDoesNotPersistCheckpoint(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $result = (new LogRetentionRunner())->run($database, function () { return 30; }, true, 1800, 0);
        $this->assertSame('complete', $result['status']);
        $this->assertSame(2, $database->batches);
        $this->assertNull($database->checkpoint);
        $this->assertFalse($database->locked);
    }
}

class RetentionRunnerTestDatabase
{
    public $locked = false;
    public $busy = false;
    public $acquisitions = 0;
    public $batches = 0;
    public $checkpoint = null;
    public $batchFailure = null;
    public $releaseFailure = null;

    public function acquireLock(): bool { ++$this->acquisitions; return $this->locked = !$this->busy; }
    public function ownsLock(): bool { return $this->locked; }
    public function releaseLock(): void
    {
        if ($this->releaseFailure !== null) {
            throw $this->releaseFailure;
        }
        $this->locked = false;
    }
    public function storageTimezone(): DateTimeZone { return new DateTimeZone('UTC'); }
    public function highWaterMark(): int { return 20000; }
    public function loadCheckpoint(): ?string { return $this->checkpoint; }
    public function saveCheckpoint(string $value): void { $this->checkpoint = $value; }
    public function nextId(int $cursor, int $high): ?int { return $cursor < $high ? $cursor + 1 : null; }
    public function processBatch(int $first, int $last, DateTimeImmutable $cutoff, bool $dryRun): int {
        if ($this->batchFailure !== null) {
            throw $this->batchFailure;
        }
        ++$this->batches;
        return 1;
    }
}

class RetentionTestClock implements \Psr\Clock\ClockInterface
{
    public DateTimeImmutable $instant;
    public function __construct(DateTimeImmutable $instant) { $this->instant = $instant; }
    public function now(): DateTimeImmutable { return $this->instant; }
}
