<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionPolicy.php';

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
        return LogRetentionPolicy::parse(self::readRaw($setting, $configFile, $readDatabase, 0));
    }

    public static function readTimezone(string $configFile, callable $readDatabase): string
    {
        $raw = self::readRaw('log_retention_timezone', $configFile, $readDatabase, LogRetentionPolicy::UNCONFIGURED_TIMEZONE);
        return LogRetentionPolicy::normaliseSetting(LogRetentionPolicy::TIMEZONE_SETTING, $raw);
    }

    private static function readRaw(string $setting, string $configFile, callable $readDatabase, $default)
    {
        $env = getenv('MISP_SETTING_MISP__' . $setting);
        if ($env !== false) {
            // Validate the original token, before EnvSetting can coerce it.
            return $env;
        }

        clearstatcache(true, $configFile);
        if (!is_readable($configFile)) {
            throw new RuntimeException('Retention configuration is unreadable.');
        }
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($configFile) &&
            !opcache_invalidate($configFile, true)) {
            throw new RuntimeException('Could not refresh cached retention configuration.');
        }
        $config = null;
        require $configFile;
        if (!is_array($config) || !isset($config['MISP']) || !is_array($config['MISP'])) {
            throw new RuntimeException('Invalid retention configuration.');
        }
        $misp = $config['MISP'];
        if (!empty($misp['system_setting_db'])) {
            $raw = $readDatabase('MISP.' . $setting);
            if ($raw !== null) {
                return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            }
        }
        return array_key_exists($setting, $misp) ? $misp[$setting] : $default;
    }
}
