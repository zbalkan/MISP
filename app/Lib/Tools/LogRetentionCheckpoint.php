<?php

declare(strict_types=1);

/**
 * Versioned sweep state. Completed or incompatible sweeps start from zero.
 */
final class LogRetentionCheckpoint
{
    public static function encode(int $days, DateTimeImmutable $cutoff, int $cursor, int $highWaterMark): string
    {
        if ($days <= 0 || $cursor < 0 || $highWaterMark < $cursor) {
            throw new InvalidArgumentException('Invalid retention checkpoint.');
        }
        return json_encode([
            'version' => 1,
            'days' => $days,
            'cutoff' => $cutoff->format('Y-m-d H:i:s.uP'),
            'timezone' => $cutoff->getTimezone()->getName(),
            'cursor' => $cursor,
            'highWaterMark' => $highWaterMark,
        ], JSON_THROW_ON_ERROR);
    }

    public static function resume(?string $raw, int $days, DateTimeImmutable $currentCutoff, int $currentHighWaterMark): ?array
    {
        if ($raw === null || $days <= 0) {
            return null;
        }
        try {
            $state = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['version'] ?? null) !== 1 ||
                ($state['days'] ?? null) !== $days ||
                ($state['timezone'] ?? null) !== $currentCutoff->getTimezone()->getName() ||
                !is_int($state['cursor'] ?? null) || !is_int($state['highWaterMark'] ?? null) ||
                $state['cursor'] < 0 || $state['cursor'] >= $state['highWaterMark'] ||
                $state['highWaterMark'] > $currentHighWaterMark ||
                !is_string($state['cutoff'] ?? null)) {
                return null;
            }
            $cutoff = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $state['cutoff']);
            if (!$cutoff || $cutoff->format('Y-m-d H:i:s.uP') !== $state['cutoff'] || $cutoff > $currentCutoff) {
                return null;
            }
            $localCutoff = $cutoff->setTimezone($currentCutoff->getTimezone());
            if ($localCutoff->format('Y-m-d H:i:s.uP') !== $state['cutoff']) {
                return null;
            }
            return [
                'cursor' => $state['cursor'],
                'highWaterMark' => $state['highWaterMark'],
                'cutoff' => $localCutoff,
            ];
        } catch (JsonException | ValueError $e) {
            return null;
        }
    }
}
