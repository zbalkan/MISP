<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionPolicyReader.php';

class LogRetentionPolicyReaderTest extends TestCase
{
    public function testTimezonePrecedenceAndExplicitUnconfiguredValue(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'retention-');
        $envName = 'MISP_SETTING_MISP__log_retention_timezone';
        $previous = getenv($envName);
        $databaseValue = null;
        $readDatabase = function ($setting) use (&$databaseValue) {
            $this->assertSame('MISP.log_retention_timezone', $setting);
            return $databaseValue;
        };
        try {
            putenv($envName);
            file_put_contents($path, '<?php $config = ["MISP" => ["system_setting_db" => true]];');
            $this->assertSame('unconfigured', LogRetentionPolicyReader::readTimezone($path, $readDatabase));
            file_put_contents($path, '<?php $config = ["MISP" => ["system_setting_db" => true, "log_retention_timezone" => "UTC"]];');
            $this->assertSame('UTC', LogRetentionPolicyReader::readTimezone($path, $readDatabase));
            $databaseValue = '"Europe/Tallinn"';
            $this->assertSame('Europe/Tallinn', LogRetentionPolicyReader::readTimezone($path, $readDatabase));
            $databaseValue = '"unconfigured"';
            $this->assertSame('unconfigured', LogRetentionPolicyReader::readTimezone($path, $readDatabase));
            putenv($envName . '=UTC');
            $this->assertSame('UTC', LogRetentionPolicyReader::readTimezone($path, $readDatabase));
            putenv($envName . '=+02:00');
            $this->expectException(InvalidArgumentException::class);
            LogRetentionPolicyReader::readTimezone($path, $readDatabase);
        } finally {
            putenv($previous === false ? $envName : $envName . '=' . $previous);
            unlink($path);
        }
    }

    public function testFreshReaderPreservesMissingNullAndNestedValues(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'config-');
        $name = 'Plugin.Example.threshold';
        $env = ConfigSettingReader::environmentVariableName($name);
        $previous = getenv($env);
        try {
            putenv($env);
            file_put_contents($path, '<?php $config = ["MISP" => [], "Plugin" => ["Example" => ["threshold" => null]]];');
            $this->assertNull(ConfigSettingReader::readRaw($name, $path, static function () { throw new RuntimeException('Database disabled'); }, 10));
            file_put_contents($path, '<?php $config = ["MISP" => [], "Plugin" => []];');
            $this->assertSame(10, ConfigSettingReader::readRaw($name, $path, static function () { return null; }, 10));
            putenv($env . '=1e2');
            $this->assertSame('1e2', ConfigSettingReader::readRaw($name, $path, static function () { return null; }, 10));
        } finally {
            putenv($previous === false ? $env : $env . '=' . $previous);
            unlink($path);
        }
    }

    public function testInvalidOverrideDoesNotSuppressOtherEnabledPolicy(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'retention-');
        $name = 'MISP_SETTING_MISP__audit_log_retention_days';
        $previous = getenv($name);
        try {
            file_put_contents($path, '<?php $config = ["MISP" => ["access_log_retention_days" => 30]];');
            putenv($name . '=invalid');
            $blocked = [];
            $this->assertTrue(LogRetentionPolicyReader::anyEnabled($path, static function () { return null; }, static function ($setting, $error) use (&$blocked) {
                $blocked[$setting] = $error;
            }));
            $this->assertSame(['MISP.audit_log_retention_days'], array_keys($blocked));
            $this->assertInstanceOf(LogRetentionConfigurationException::class, $blocked['MISP.audit_log_retention_days']);
        } finally {
            putenv($previous === false ? $name : $name . '=' . $previous);
            unlink($path);
        }
    }

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
