<?php

declare(strict_types=1);

/**
 * Named locks coordinate one database server, not a replication cluster.
 * Reject known clustered configurations. Operators must also ensure that
 * all retention jobs use the same writable server and stop jobs before
 * changing the database topology.
 */
final class LogRetentionTopology
{
    public static function assertSupported(array $variables): void
    {
        $variables = array_change_key_case($variables, CASE_LOWER);
        $wsrep = strtoupper((string)($variables['wsrep_on'] ?? 'OFF'));
        $provider = strtolower(trim((string)($variables['wsrep_provider'] ?? 'none')));
        if (!in_array($wsrep, ['OFF', '0'], true) || !in_array($provider, ['none', ''], true)) {
            throw new RuntimeException('Log retention does not support Galera/wsrep configurations.');
        }
        if (trim((string)($variables['group_replication_group_name'] ?? '')) !== '') {
            throw new RuntimeException('Log retention does not support MySQL Group Replication configurations.');
        }
    }
}
