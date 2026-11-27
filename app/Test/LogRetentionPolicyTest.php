<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionPolicy.php';

class LogRetentionPolicyTest extends TestCase
{
    public function testValidPeriods(): void
    {
        foreach ([0, 1, 365, 36500, '0', '365'] as $value) {
            $this->assertSame((int)$value, LogRetentionPolicy::parse($value));
        }
    }

    public function testInvalidPeriods(): void
    {
        foreach ([null, false, true, -1, 36501, 1.0, 1.5, '', '01', '1.5', '1e2', ' 1', "1\n", [], '999999999999999999999'] as $value) {
            try {
                LogRetentionPolicy::parse($value);
                $this->fail('Invalid retention period accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Retention must be an integer between 0 and 36500 days.', $e->getMessage());
            }
        }
    }

    public function testDisabledAndCalendarDayCutoff(): void
    {
        $now = new DateTimeImmutable('2026-03-29 12:00:00', new DateTimeZone('Europe/Tallinn'));
        $this->assertNull(LogRetentionPolicy::cutoff(0, $now));
        $this->assertSame('2026-03-28 12:00:00 +02:00', LogRetentionPolicy::cutoff(1, $now)->format('Y-m-d H:i:s P'));
    }
}
