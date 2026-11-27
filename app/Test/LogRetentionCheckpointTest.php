<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionCheckpoint.php';

class LogRetentionCheckpointTest extends TestCase
{
    public function testResumePreservesOriginalCutoffAndBounds(): void
    {
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $raw = LogRetentionCheckpoint::encode(365, $cutoff, 10, 100);
        $state = LogRetentionCheckpoint::resume($raw, 365, $cutoff->modify('+1 day'), 120);
        $this->assertSame(10, $state['cursor']);
        $this->assertSame(100, $state['highWaterMark']);
        $this->assertEquals($cutoff, $state['cutoff']);
    }

    public function testInvalidOrObsoleteStateRestarts(): void
    {
        $cutoff = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $raw = LogRetentionCheckpoint::encode(365, $cutoff, 10, 100);
        $this->assertNull(LogRetentionCheckpoint::resume(null, 365, $cutoff, 100));
        $this->assertNull(LogRetentionCheckpoint::resume('{', 365, $cutoff, 100));
        $this->assertNull(LogRetentionCheckpoint::resume($raw, 366, $cutoff, 100));
        $this->assertNull(LogRetentionCheckpoint::resume($raw, 0, $cutoff, 100));
        $this->assertNull(LogRetentionCheckpoint::resume($raw, 365, $cutoff, 99));
        $this->assertNull(LogRetentionCheckpoint::resume($raw, 365, $cutoff->modify('-1 day'), 100));
        $this->assertNull(LogRetentionCheckpoint::resume(LogRetentionCheckpoint::encode(365, $cutoff, 100, 100), 365, $cutoff, 100));
    }
}
