<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionPolicyReader.php';

class LogRetentionPolicyReaderTest extends TestCase
{
    public function testRefreshAndPrecedence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'retention-');
        $envName = 'MISP_SETTING_MISP__audit_log_retention_days';
        $previous = getenv($envName);
        $databaseValue = null;
        $readDatabase = function ($setting) use (&$databaseValue) {
            $this->assertSame('MISP.audit_log_retention_days', $setting);
            return $databaseValue;
        };
        try {
            putenv($envName);
            file_put_contents($path, '<?php $config = ["MISP" => ["audit_log_retention_days" => 30, "system_setting_db" => true]];');
            $this->assertSame(30, LogRetentionPolicyReader::read('audit_log_retention_days', $path, $readDatabase));
            $databaseValue = '365';
            $this->assertSame(365, LogRetentionPolicyReader::read('audit_log_retention_days', $path, $readDatabase));
            putenv($envName . '=0');
            $this->assertSame(0, LogRetentionPolicyReader::read('audit_log_retention_days', $path, $readDatabase));
            putenv($envName);
            $databaseValue = null;
            file_put_contents($path, '<?php $config = ["MISP" => ["audit_log_retention_days" => 90]];');
            $this->assertSame(90, LogRetentionPolicyReader::read('audit_log_retention_days', $path, $readDatabase));
            putenv($envName . '=1e2');
            $this->expectException(InvalidArgumentException::class);
            LogRetentionPolicyReader::read('audit_log_retention_days', $path, $readDatabase);
        } finally {
            putenv($previous === false ? $envName : $envName . '=' . $previous);
            unlink($path);
        }
    }
}
