<?php

declare(strict_types=1);

/**
 * Non-mutating, fresh resolution of a plain (non-encrypted) setting.
 * Unlike ConfigLoadTask, this does not populate the global Configure store.
 * Environment tokens are deliberately not cast; the caller validates them.
 */
final class ConfigSettingReader
{
    public const ENV_PREFIX = 'MISP_SETTING_';

    public static function environmentVariableName(string $setting): string
    {
        return self::ENV_PREFIX . str_replace('.', '__', $setting);
    }

    public static function readRaw(string $setting, string $configFile, callable $readDatabase, $default)
    {
        $env = getenv(self::environmentVariableName($setting));
        if ($env !== false) {
            return $env;
        }
        clearstatcache(true, $configFile);
        if (!is_readable($configFile)) {
            throw new RuntimeException('Configuration is unreadable.');
        }
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($configFile) &&
            !opcache_invalidate($configFile, true)) {
            throw new RuntimeException('Could not refresh cached configuration.');
        }
        $config = null;
        require $configFile;
        if (!is_array($config) || !isset($config['MISP']) || !is_array($config['MISP'])) {
            throw new RuntimeException('Invalid configuration.');
        }
        // ConfigLoadTask decides whether to load the database before applying
        // environment overrides. The database reader must enforce the same
        // allowed-setting and non-encrypted-value contract as its caller.
        if (!empty($config['MISP']['system_setting_db'])) {
            $raw = $readDatabase($setting);
            if ($raw !== null) {
                return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            }
        }
        $value = $config;
        foreach (explode('.', $setting) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
