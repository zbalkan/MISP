<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionPolicy.php';

class LogRetentionSettingsTest extends TestCase
{
    public function testCombinationAllowsIndependentAccessAndRecovery(): void
    {
        $settings = ['MISP.audit_log_retention_days' => 30, 'MISP.log_retention_timezone' => 'invalid'];
        $read = static function ($name) use (&$settings) { return $settings[$name] ?? null; };
        LogRetentionPolicy::validateCombination('MISP.access_log_retention_days', 30, $read);
        LogRetentionPolicy::validateCombination('MISP.audit_log_retention_days', 0, $read);
        LogRetentionPolicy::validateCombination(LogRetentionPolicy::TIMEZONE_SETTING, 'UTC', $read);
        $this->expectException(InvalidArgumentException::class);
        LogRetentionPolicy::validateCombination('MISP.log_retention_days', 30, $read);
    }


    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRawSettingsRejectInvalidValuesEvenWithForce(): void
    {
        if (!class_exists('App', false)) {
            class_alias(RetentionSettingsApp::class, 'App');
        }
        if (!class_exists('AppModel', false)) {
            class_alias(RetentionSettingsModel::class, 'AppModel');
        }
        if (!function_exists('__')) {
            function __($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }
        }
        require_once __DIR__ . '/../Model/Server.php';
        $server = (new ReflectionClass('Server'))->newInstanceWithoutConstructor();
        foreach (LogRetentionPolicy::DAY_SETTINGS as $name) {
            $setting = ['name' => $name, 'type' => 'numeric', 'test' => 'testLogRetentionDays'];
            foreach ([null, true, false, 1.0, '1.0', '1e2', ' 30', '30 ', '01', -1, 36501, [], ''] as $invalid) {
                $this->assertIsString($server->serverSettingsEditValue('SYSTEM', $setting, $invalid, true, true));
                $this->assertIsString($server->testLogRetentionDays($invalid));
            }
            foreach ([0, '0', 30, '365', 36500] as $valid) {
                $this->assertTrue($server->testLogRetentionDays($valid));
                $this->assertIsInt(LogRetentionPolicy::normaliseSetting($name, $valid));
            }
        }
        $timezone = ['name' => LogRetentionPolicy::TIMEZONE_SETTING, 'type' => 'string'];
        foreach ([null, '', 'GMT+2', '+02:00', ' Europe/Tallinn', true, 123] as $invalid) {
            $this->assertIsString($server->serverSettingsEditValue('SYSTEM', $timezone, $invalid, true, true));
        }
        foreach (['UTC', 'Europe/Tallinn', 'unconfigured'] as $valid) {
            $this->assertTrue($server->testLogRetentionTimezone($valid));
        }
        // Unrelated numeric settings retain their established behaviour.
        $this->assertSame(0, Server::normaliseSettingValue(['type' => 'numeric'], '0.7'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAdminShellAndEnvironmentPreserveRawRetentionValues(): void
    {
        class_alias(RetentionSettingsApp::class, 'App');
        class_alias(RetentionSettingsModel::class, 'AppModel');
        class_alias(RetentionSettingsShell::class, 'AppShell');
        class_alias(RetentionSettingsConfigure::class, 'Configure');
        if (!function_exists('__')) {
            function __($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }
        }
        require_once __DIR__ . '/../Model/Server.php';
        require_once __DIR__ . '/../Model/SystemSetting.php';
        require_once __DIR__ . '/../Lib/Tools/EnvSetting.php';
        require_once __DIR__ . '/../Console/Command/AdminShell.php';
        $shell = new AdminShell();
        $shell->Server = new class extends Server {
            public function __construct() {}

            public $saved = [];
            public $audit = [];
            public function getSettingData($settingName, $withOptions = true)
            {
                return ['name' => $settingName, 'type' => 'numeric', 'test' => 'testLogRetentionDays'];
            }
            public function serverSettingsSaveValue($setting, $value, $fileOnly = false)
            {
                $this->saved[$setting] = $value;
                return true;
            }
            protected function loadLog() { return $this; }
            public function createLogEntry(...$args) { $this->audit[] = $args; }
        };
        $name = 'MISP.audit_log_retention_days';
        $envName = 'MISP_SETTING_MISP__audit_log_retention_days';
        $previous = getenv($envName);
        try {
            putenv($envName);
            $shell->params = ['null' => false, 'force' => true];
            foreach (['1e2', '30.5', 'true', ' 30', '01'] as $invalid) {
                $shell->args = [$name, $invalid];
                try {
                    $shell->setSetting();
                    $this->fail('Expected invalid CLI value to be rejected.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('Setting change rejected', $e->getMessage());
                }
            }
            $this->assertSame([], $shell->Server->saved);
            $shell->args = [$name, '365'];
            try {
                $shell->setSetting();
                $this->fail('Expected missing writer timezone to reject activation even with force.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('writer timezone', $e->getMessage());
            }
            $this->assertSame([], $shell->Server->saved);
            RetentionSettingsConfigure::$settings[LogRetentionPolicy::TIMEZONE_SETTING] = 'UTC';
            $shell->setSetting();
            $this->assertSame([$name => 365], $shell->Server->saved);
            $this->assertCount(1, $shell->Server->audit);
            RetentionSettingsConfigure::$settings[$name] = 365;
            $this->assertIsString($shell->Server->serverSettingsEditValue('SYSTEM', ['name' => LogRetentionPolicy::TIMEZONE_SETTING], 'unconfigured', true, true));
            $this->assertSame([$name => 365], $shell->Server->saved);
            $timezoneSetting = ['name' => LogRetentionPolicy::TIMEZONE_SETTING];
            $this->assertSame('Disable audit and application log retention before clearing the writer timezone.',
                $shell->Server->serverSettingsEditValue('SYSTEM', $timezoneSetting, 'unconfigured', true, true));
            RetentionSettingsConfigure::$settings[$name] = 'invalid';
            $this->assertSame('Correct invalid audit or application log retention periods before clearing the writer timezone.',
                $shell->Server->serverSettingsEditValue('SYSTEM', $timezoneSetting, 'unconfigured', true, true));
            RetentionSettingsConfigure::$settings[$name] = 365;
            // Recovery must permit disabling a period even with an invalid timezone.
            RetentionSettingsConfigure::$settings[LogRetentionPolicy::TIMEZONE_SETTING] = 'invalid';
            $shell->args = [$name, '0'];
            $shell->setSetting();
            $this->assertSame([$name => 0], $shell->Server->saved);
            putenv($envName . '=1e2');
            $this->assertSame('1e2', EnvSetting::readSettings()[$name]);
            putenv($envName . '=90');
            $this->assertSame(90, EnvSetting::readSettings()[$name]);
            $this->assertIsString($shell->Server->serverSettingsEditValue('SYSTEM', $shell->Server->getSettingData($name), '30', true, true));
            $this->assertSame([$name => 0], $shell->Server->saved);
        } finally {
            putenv($previous === false ? $envName : $envName . '=' . $previous);
        }
    }
}

class RetentionSettingsApp
{
    public static function uses($class, $package) {}
}

class RetentionSettingsModel {}

class RetentionSettingsShell
{
    public $Server;
    public $args = [];
    public $params = [];
    public function out($message) {}
    public function error($message, $details = null) { throw new RuntimeException($message . ': ' . $details); }
}

class RetentionSettingsConfigure
{
    public static $settings = [];
    public static function read($key) { return self::$settings[$key] ?? null; }
}
