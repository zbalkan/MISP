<?php

declare(strict_types=1);

require_once __DIR__ . '/LogRetentionPolicyReader.php';

final class LogRetentionDispatch
{
    public static function run(string $configFile, callable $readDatabase, callable $claim, callable $enqueue): bool
    {
        $enabled = false;
        foreach (LogRetentionPolicy::DAY_SETTINGS as $name) {
            if (LogRetentionPolicyReader::read(substr($name, 5), $configFile, $readDatabase) > 0) {
                $enabled = true;
            }
        }
        if (!$enabled || !$claim()) {
            return false;
        }
        // Claim before enqueue: a crash or uncertain enqueue can skip one
        // interval, but must not cause an immediate duplicate dispatch.
        $enqueue();
        return true;
    }
}
