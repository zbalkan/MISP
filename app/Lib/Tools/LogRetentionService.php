<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionDatabase.php';
require_once __DIR__ . '/LogRetentionRunner.php';
require_once __DIR__ . '/LogRetentionPolicyReader.php';

/** Shared entry point for the supported MISP log models. */
final class LogRetentionService
{
    private const TABLES = ['AuditLog' => 'audit_logs', 'AccessLog' => 'access_logs', 'Log' => 'logs'];

    /** Run configured policies once, using caller-specific reporting. */
    public static function runAllConfigured(callable $runTable, callable $report, callable $reportFailure): bool
    {
        $success = true;
        $index = 0;
        foreach (self::TABLES as $alias => $category) {
            $progress = (int) floor($index * 100 / count(self::TABLES));
            $report($category, null, $progress);
            try {
                $result = $runTable($alias);
            } catch (Throwable $e) {
                // Never reuse a potentially uncertain database session.
                $reportFailure($category, $e, $progress);
                return false;
            }
            $report($category, $result, min(99, (int) floor(++$index * 100 / count(self::TABLES))));
            if (!in_array($result['status'], ['complete', 'disabled'], true)) {
                $success = false;
            }
        }
        return $success;
    }

    public static function describeFailure(Throwable $error): array
    {
        $messages = [get_class($error) . ': ' . $error->getMessage()];
        if ($error instanceof LogRetentionCleanupException) {
            $messages = array_merge($messages, self::describeFailure($error->getCleanupFailure()));
        }
        if ($error->getPrevious() !== null) {
            $messages = array_merge($messages, self::describeFailure($error->getPrevious()));
        }
        return $messages;
    }

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
        $started = hrtime(true);
        $days = null;
        try {
            $days = LogRetentionPolicyReader::read($setting, $configFile, [$database, 'readPolicySetting']);
            $configuredTimezone = null;
            if ($model->alias !== 'AccessLog' && $days > 0) {
                $configuredTimezone = LogRetentionPolicyReader::readTimezone($configFile, [$database, 'readPolicySetting']);
                if ($configuredTimezone !== LogRetentionPolicy::UNCONFIGURED_TIMEZONE) {
                    if ($logTimezone !== null && $logTimezone->getName() !== $configuredTimezone) {
                        throw new LogRetentionConfigurationException('CLI writer timezone conflicts with configured retention timezone.');
                    }
                    $logTimezone = new DateTimeZone($configuredTimezone);
                } elseif ($logTimezone === null) {
                    throw new LogRetentionConfigurationException('Configure the log writer timezone before enabling local log retention.');
                }
                $database->setWriterTimezone($logTimezone);
            }
            $result = (new LogRetentionRunner())->run($database, static function () use ($database, $setting, $configFile, $model, $configuredTimezone) {
                $days = LogRetentionPolicyReader::read($setting, $configFile, [$database, 'readPolicySetting']);
                if ($days > 0 && $model->alias !== 'AccessLog' &&
                    LogRetentionPolicyReader::readTimezone($configFile, [$database, 'readPolicySetting']) !== $configuredTimezone) {
                    throw new LogRetentionConfigurationException('Retention writer timezone changed; restart the sweep with the current configuration.');
                }
                return $days;
            }, $dryRun);
        } catch (LogRetentionConfigurationException $e) {
            // Only typed configuration errors before mutation reach this catch.
            // The runner handles later policy errors at a batch boundary and
            // preserves counts; any cleanup failure remains fatal.
            return ['status' => 'blocked', 'reason' => $e->getMessage(), 'complete' => false,
                'affected' => 0, 'batches' => 0, 'days' => $days, 'cutoff' => null,
                'durationSeconds' => (hrtime(true) - $started) / 1e9, 'dryRun' => $dryRun];
        }
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
                $database->setWriterTimezone(new DateTimeZone($configuredTimezone));
            }
        }
        $cutoff = $legacyCutoff ?? LogRetentionPolicy::cutoff($days, (new SystemClock())->now()->setTimezone($database->storageTimezone()));
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
}
