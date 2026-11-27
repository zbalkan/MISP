<?php

declare(strict_types=1);

/**
 * Retention periods are whole calendar days. Zero disables retention.
 */
final class LogRetentionPolicy
{
    public const DAY_SETTINGS = [
        'MISP.audit_log_retention_days',
        'MISP.access_log_retention_days',
        'MISP.log_retention_days',
    ];
    public const TIMEZONE_SETTING = 'MISP.log_retention_timezone';
    public const UNCONFIGURED_TIMEZONE = 'unconfigured';

    public static function isSetting(string $name): bool
    {
        return in_array($name, self::DAY_SETTINGS, true) || $name === self::TIMEZONE_SETTING;
    }

    public static function normaliseSetting(string $name, $value)
    {
        if ($name === self::TIMEZONE_SETTING) {
            if ($value === self::UNCONFIGURED_TIMEZONE) {
                return $value;
            }
            if (!is_string($value) || !in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                throw new InvalidArgumentException('Specify a valid IANA log writer timezone or unconfigured.');
            }
            return $value;
        }
        if (!in_array($name, self::DAY_SETTINGS, true)) {
            throw new InvalidArgumentException('Unknown retention setting.');
        }
        return self::parse($value);
    }

    /**
     * Validate a proposed change against the current effective settings.
     * Disabling a period is always allowed, including during recovery from
     * an invalid configuration. Runtime checks remain authoritative when
     * settings are changed externally or concurrently.
     */
    public static function validateCombination(string $name, $value, callable $read): void
    {
        $localSettings = ['MISP.audit_log_retention_days', 'MISP.log_retention_days'];
        if (in_array($name, $localSettings, true) && $value > 0) {
            $timezone = self::normaliseSetting(self::TIMEZONE_SETTING, $read(self::TIMEZONE_SETTING) ?? self::UNCONFIGURED_TIMEZONE);
            if ($timezone === self::UNCONFIGURED_TIMEZONE) {
                throw new InvalidArgumentException('Configure the log writer timezone before enabling audit or application log retention.');
            }
        } elseif ($name === self::TIMEZONE_SETTING && $value === self::UNCONFIGURED_TIMEZONE) {
            foreach ($localSettings as $localSetting) {
                if (self::parse($read($localSetting) ?? 0) > 0) {
                    throw new InvalidArgumentException('Disable audit and application log retention before clearing the writer timezone.');
                }
            }
        }
    }

    public static function parse($value): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/', $value)) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0 || $value > 36500) {
            throw new InvalidArgumentException('Retention must be an integer between 0 and 36500 days.');
        }
        return $value;
    }

    public static function cutoff($value, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $days = self::parse($value);
        return $days === 0 ? null : $now->sub(new DateInterval('P' . $days . 'D'));
    }
}
