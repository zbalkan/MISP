<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionPolicy.php';
require_once __DIR__ . '/ConfigSettingReader.php';

/**
 * Read one retention setting without mutating the process-wide Configure store.
 * Match ConfigLoadTask precedence: file, database (if enabled), environment.
 */
final class LogRetentionPolicyReader
{
    public static function read(string $setting, string $configFile, callable $readDatabase): int
    {
        if (!in_array('MISP.' . $setting, LogRetentionPolicy::DAY_SETTINGS, true)) {
            throw new InvalidArgumentException('Unknown retention setting.');
        }
        return LogRetentionPolicy::parse(ConfigSettingReader::readRaw('MISP.' . $setting, $configFile, $readDatabase, 0));
    }

    public static function readTimezone(string $configFile, callable $readDatabase): string
    {
        $raw = ConfigSettingReader::readRaw(LogRetentionPolicy::TIMEZONE_SETTING, $configFile, $readDatabase, LogRetentionPolicy::UNCONFIGURED_TIMEZONE);
        return LogRetentionPolicy::normaliseSetting(LogRetentionPolicy::TIMEZONE_SETTING, $raw);
    }

}
