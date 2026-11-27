<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionDatabase.php';
require_once __DIR__ . '/LogRetentionRunner.php';
require_once __DIR__ . '/LogRetentionPolicyReader.php';

/** Shared entry point for the supported MISP log models. */
final class LogRetentionService
{
    public static function deleteAccessLogsBefore($model, DateTimeImmutable $cutoff): int
    {
        if ($model->alias !== 'AccessLog') {
            throw new InvalidArgumentException('Expected the access log model.');
        }
        return (new LogRetentionRunner())->runFixedCutoff(new LogRetentionDatabase($model), $cutoff);
    }

    public static function runConfigured($model, string $configFile, bool $dryRun = false, ?DateTimeZone $logTimezone = null): array
    {
        $settings = ['AuditLog' => 'audit_log_retention_days', 'AccessLog' => 'access_log_retention_days', 'Log' => 'log_retention_days'];
        $database = new LogRetentionDatabase($model, $logTimezone);
        $setting = $settings[$model->alias];
        $configuredTimezone = null;
        if ($model->alias !== 'AccessLog' && LogRetentionPolicyReader::read($setting, $configFile, [$database, 'readPolicySetting']) > 0) {
            $configuredTimezone = LogRetentionPolicyReader::readTimezone($configFile, [$database, 'readPolicySetting']);
            if ($configuredTimezone !== LogRetentionPolicy::UNCONFIGURED_TIMEZONE) {
                if ($logTimezone !== null && $logTimezone->getName() !== $configuredTimezone) {
                    throw new InvalidArgumentException('CLI writer timezone conflicts with configured retention timezone.');
                }
                $logTimezone = new DateTimeZone($configuredTimezone);
            }
            $database = new LogRetentionDatabase($model, $logTimezone);
        }
        $result = (new LogRetentionRunner())->run($database, static function () use ($database, $setting, $configFile, $model, $configuredTimezone) {
            $days = LogRetentionPolicyReader::read($setting, $configFile, [$database, 'readPolicySetting']);
            if ($days > 0 && $model->alias !== 'AccessLog' &&
                LogRetentionPolicyReader::readTimezone($configFile, [$database, 'readPolicySetting']) !== $configuredTimezone) {
                throw new RuntimeException('Retention writer timezone changed; restart the sweep with the current configuration.');
            }
            return $days;
        }, $dryRun);
        return self::formatCutoff($database, $result);
    }

    public static function runExplicit($model, $days, bool $dryRun = false, ?DateTimeZone $logTimezone = null, ?DateTimeImmutable $legacyCutoff = null, ?string $configFile = null): array
    {
        if ($legacyCutoff === null) {
            $days = LogRetentionPolicy::parse($days);
        } elseif ($model->alias !== 'AccessLog') {
            throw new InvalidArgumentException('Legacy cutoff is only supported for access logs.');
        }
        if (!is_numeric($days) || $days <= 0) {
            throw new InvalidArgumentException('Explicit retention requires a positive duration.');
        }
        $database = new LogRetentionDatabase($model, $logTimezone);
        if ($model->alias !== 'AccessLog' && $configFile !== null) {
            $configuredTimezone = LogRetentionPolicyReader::readTimezone($configFile, [$database, 'readPolicySetting']);
            if ($configuredTimezone !== LogRetentionPolicy::UNCONFIGURED_TIMEZONE) {
                if ($logTimezone !== null && $logTimezone->getName() !== $configuredTimezone) {
                    throw new InvalidArgumentException('CLI writer timezone conflicts with configured retention timezone.');
                }
                $database = new LogRetentionDatabase($model, new DateTimeZone($configuredTimezone));
            }
        }
        $cutoff = $legacyCutoff ?? LogRetentionPolicy::cutoff($days, new DateTimeImmutable('now', $database->storageTimezone()));
        $result = (new LogRetentionRunner())->runFixedCutoffResult($database, $cutoff, $dryRun);
        $result['days'] = $days;
        return self::formatCutoff($database, $result);
    }

    private static function formatCutoff(LogRetentionDatabase $database, array $result): array
    {
        if ($result['cutoff'] !== null) {
            $result['cutoff'] = $database->storageCutoff($result['cutoff']);
            $result['timezone'] = $database->storageTimezone()->getName();
        }
        return $result;
    }

    /**
     * The reader receives the adapter so configured policies can be refreshed
     * from the same database session. Local-time tables require the known
     * writer timezone. Explicit one-time operations must not share scheduled
     * checkpoints.
     */
    public static function run($model, callable $readPolicy, bool $dryRun = false, ?DateTimeZone $logTimezone = null, bool $persistCheckpoint = true): array
    {
        $database = new LogRetentionDatabase($model, $logTimezone);
        return (new LogRetentionRunner())->run(
            $database,
            static function () use ($readPolicy, $database) { return $readPolicy($database); },
            $dryRun,
            1800,
            200000,
            $persistCheckpoint
        );
    }
}
