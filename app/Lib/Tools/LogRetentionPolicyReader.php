<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionPolicy.php';
require_once __DIR__ . '/ConfigSettingReader.php';
require_once __DIR__ . '/LogRetentionConfigurationException.php';

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
        try {
            $raw = ConfigSettingReader::readRaw('MISP.' . $setting, $configFile, $readDatabase, 0);
        } catch (JsonException $e) {
            throw new LogRetentionConfigurationException('Invalid stored retention period.', 0, $e);
        }
        try {
            return LogRetentionPolicy::parse($raw);
        } catch (InvalidArgumentException $e) {
            throw new LogRetentionConfigurationException($e->getMessage(), 0, $e);
        }
    }

    public static function readTimezone(string $configFile, callable $readDatabase): string
    {
        try {
            $raw = ConfigSettingReader::readRaw(LogRetentionPolicy::TIMEZONE_SETTING, $configFile, $readDatabase, LogRetentionPolicy::UNCONFIGURED_TIMEZONE);
        } catch (JsonException $e) {
            throw new LogRetentionConfigurationException('Invalid stored retention timezone.', 0, $e);
        }
        try {
            return LogRetentionPolicy::normaliseSetting(LogRetentionPolicy::TIMEZONE_SETTING, $raw);
        } catch (InvalidArgumentException $e) {
            throw new LogRetentionConfigurationException($e->getMessage(), 0, $e);
        }
    }

    /** Invalid individual periods must not suppress other enabled categories. */
    public static function anyEnabled(string $configFile, callable $readDatabase, callable $reportBlocked): bool
    {
        $enabled = false;
        foreach (LogRetentionPolicy::DAY_SETTINGS as $name) {
            try {
                if (self::read(substr($name, 5), $configFile, $readDatabase) > 0) {
                    $enabled = true;
                }
            } catch (LogRetentionConfigurationException $e) {
                $reportBlocked($name, $e);
            }
        }
        return $enabled;
    }
}
