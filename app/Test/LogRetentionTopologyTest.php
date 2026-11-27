<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Lib/Tools/LogRetentionTopology.php';

class LogRetentionTopologyTest extends TestCase
{
    public function testStandaloneConfigurationsAreAccepted(): void
    {
        LogRetentionTopology::assertSupported([]);
        LogRetentionTopology::assertSupported(['wsrep_on' => 'OFF', 'wsrep_provider' => 'none']);
        $this->addToAssertionCount(2);
    }

    public function testKnownClusterConfigurationsAreRejected(): void
    {
        foreach ([
            ['wsrep_on' => 'ON', 'wsrep_provider' => 'none'],
            ['wsrep_on' => 'OFF', 'wsrep_provider' => '/usr/lib/galera/libgalera_smm.so'],
            ['group_replication_group_name' => ''],
            ['WSREP_ON' => 'ON'],
        ] as $variables) {
            try {
                LogRetentionTopology::assertSupported($variables);
                $this->fail('Cluster configuration accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('does not support', $e->getMessage());
            }
        }
    }
}
