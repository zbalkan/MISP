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

    public function testMalformedAndInconsistentCutoffRestarts(): void
    {
        $current = new DateTimeImmutable('2026-04-01', new DateTimeZone('UTC'));
        $raw = LogRetentionCheckpoint::encode(30, $current->modify('-1 day'), 10, 100);
        $state = json_decode($raw, true);
        foreach (['2026-02-30 00:00:00.000000+00:00', "2026-03-01 00:00:00.000000+00:00" . chr(0), '2026-03-01 00:00:00.000000+02:00'] as $invalid) {
            $state['cutoff'] = $invalid;
            $this->assertNull(LogRetentionCheckpoint::resume(json_encode($state), 30, $current, 100));
        }
        $this->assertNull(LogRetentionCheckpoint::resume($raw, 30, $current->setTimezone(new DateTimeZone('Europe/Tallinn')), 100));
    }

    public function testMalformedCutoffPayloadTypesRestartWithoutThrowing(): void
    {
        $current = new DateTimeImmutable('2026-04-01', new DateTimeZone('UTC'));
        $valid = json_decode(LogRetentionCheckpoint::encode(30, $current->modify('-1 day'), 10, 100), true);
        foreach ([[], ['unexpected'], ['date' => '2026-03-31'], 123, false] as $invalid) {
            $state = $valid;
            $state['cutoff'] = $invalid;
            $this->assertNull(LogRetentionCheckpoint::resume(json_encode($state, JSON_THROW_ON_ERROR), 30, $current, 100));
        }
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
