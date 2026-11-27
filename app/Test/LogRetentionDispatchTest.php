<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../Lib/Tools/LogRetentionDispatch.php';

class LogRetentionDispatchTest extends TestCase
{
    public function testDisabledAndEnabledDispatch(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'retention');
        $claims = 0;
        $enqueues = 0;
        $claim = static function () use (&$claims) { return ++$claims === 1; };
        $enqueue = static function () use (&$enqueues) { ++$enqueues; };
        $read = static function () { return null; };
        $old = [];
        foreach (LogRetentionPolicy::DAY_SETTINGS as $setting) {
            $key = 'MISP_SETTING_MISP__' . substr($setting, 5);
            $old[$key] = getenv($key);
            putenv($key);
        }
        try {
            file_put_contents($file, '<?php $config = ["MISP" => []];');
            $this->assertFalse(LogRetentionDispatch::run($file, $read, $claim, $enqueue));
            $this->assertSame(0, $claims);
            file_put_contents($file, '<?php $config = ["MISP" => ["access_log_retention_days" => 30]];');
            $this->assertTrue(LogRetentionDispatch::run($file, $read, $claim, $enqueue));
            $this->assertFalse(LogRetentionDispatch::run($file, $read, $claim, $enqueue));
            $this->assertSame(1, $enqueues);
        } finally {
            unlink($file);
            foreach ($old as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }
}
