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

    public function testDisabledDoesNotAcquireLock(): void
    {
        $database = new RetentionRunnerTestDatabase();
        $result = (new LogRetentionRunner())->run($database, function () { return 0; });
        $this->assertSame('disabled', $result['status']);
        $this->assertSame(0, $database->acquisitions);
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
    public $acquisitions = 0;
    public $batches = 0;
    public $checkpoint = null;

    public function acquireLock(): bool { ++$this->acquisitions; return $this->locked = true; }
    public function ownsLock(): bool { return $this->locked; }
    public function releaseLock(): void { $this->locked = false; }
    public function storageTimezone(): DateTimeZone { return new DateTimeZone('UTC'); }
    public function highWaterMark(): int { return 20000; }
    public function loadCheckpoint(): ?string { return $this->checkpoint; }
    public function saveCheckpoint(string $value): void { $this->checkpoint = $value; }
    public function nextId(int $cursor, int $high): ?int { return $cursor < $high ? $cursor + 1 : null; }
    public function processBatch(int $first, int $last, DateTimeImmutable $cutoff, bool $dryRun): int { ++$this->batches; return 1; }
}
