<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionService.php';
require_once __DIR__ . '/../Lib/Tools/BackgroundJobs/BackgroundJob.php';

class LogRetentionScheduledJobTest extends TestCase
{
    public function testReportsIncompleteSweepAndContinuesIndependentTables(): void
    {
        $calls = [];
        $reports = [];
        $result = LogRetentionService::runAllConfigured(function ($alias) use (&$calls) {
            $calls[] = $alias;
            return ['status' => $alias === 'AuditLog' ? 'partial' : 'disabled', 'days' => 30,
                'cutoff' => null, 'batches' => 1, 'affected' => 2, 'durationSeconds' => 0.1, 'reason' => 'runtime_limit'];
        }, function ($category, $result, $progress) use (&$reports) {
            $reports[] = [$category, $result, $progress];
        }, static function ($category, $error, $progress) { throw $error; });
        $this->assertFalse($result);
        $this->assertSame(['AuditLog', 'AccessLog', 'Log'], $calls);
        $this->assertSame('partial', $reports[1][1]['status']);
        $this->assertSame(99, $reports[5][2]);
    }

    public function testFailureStopsBeforeNextTable(): void
    {
        $calls = [];
        $reports = [];
        $this->assertFalse(LogRetentionService::runAllConfigured(function ($alias) use (&$calls) {
            $calls[] = $alias;
            throw new RuntimeException('database unavailable');
        }, static function ($category, $result, $progress) {}, function ($category, $error, $progress) use (&$reports) {
            $reports = LogRetentionService::describeFailure($error);
        }));
        $this->assertSame(['AuditLog'], $calls);
        $this->assertStringContainsString('database unavailable', $reports[0]);
    }

    public function testDisabledPoliciesCompleteWithoutDeletion(): void
    {
        $this->assertTrue(LogRetentionService::runAllConfigured(static function ($alias) {
            return ['status' => 'disabled', 'days' => 0, 'cutoff' => null, 'batches' => 0,
                'affected' => 0, 'durationSeconds' => 0, 'reason' => 'disabled'];
        }, static function ($category, $result, $progress) {}, static function ($category, $error, $progress) { throw $error; }));
    }

    public function testOnlyRetentionJobReceivesRawRetentionOverrides(): void
    {
        $name = 'MISP_SETTING_MISP__audit_log_retention_days';
        $timezone = 'MISP_SETTING_MISP__log_retention_timezone';
        $old = getenv($name);
        $oldTimezone = getenv($timezone);
        try {
            putenv($name . '=0');
            putenv($timezone . '=invalid');
            $method = new ReflectionMethod(BackgroundJob::class, 'processEnvironment');
            $method->setAccessible(true);
            $job = new BackgroundJob(['id' => 'test', 'command' => 'admin', 'args' => ['enforceLogRetention', 1]]);
            $env = $method->invoke($job);
            $this->assertSame('0', $env[$name]);
            $this->assertSame('invalid', $env[$timezone]);
            $this->assertSame('test', $env['BACKGROUND_JOB_ID']);
            $other = new BackgroundJob(['id' => 'test', 'command' => 'admin', 'args' => ['updateGalaxies']]);
            $this->assertSame(['BACKGROUND_JOB_ID' => 'test'], $method->invoke($other));
        } finally {
            putenv($old === false ? $name : $name . '=' . $old);
            putenv($oldTimezone === false ? $timezone : $timezone . '=' . $oldTimezone);
        }
    }
}
