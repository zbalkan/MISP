<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionPolicy.php';

class LogRetentionSettingsTest extends TestCase
{
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
}

class RetentionSettingsApp
{
    public static function uses($class, $package) {}
}

class RetentionSettingsModel {}
