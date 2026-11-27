<?php

declare(strict_types=1);

/**
 * Retention periods are whole calendar days. Zero disables retention.
 */
final class LogRetentionPolicy
{
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
