<?php
App::uses('LogRetentionService', 'Tools');
App::uses('LogRetentionPolicy', 'Tools');

/**
 * @property Log $Log
 * @property AuditLog $AuditLog
 * @property AccessLog $AccessLog
 * @property Server $Server
 */
class LogShell extends AppShell
{
    public $uses = ['Log', 'AuditLog', 'AccessLog', 'Server'];

    public function getOptionParser()
    {
        $parser = parent::getOptionParser();
        $parser->addSubcommand('auditStatistics', [
            'help' => __('Show statistics for audit logs.'),
        ]);
        $parser->addSubcommand('accessStatistics', [
            'help' => __('Show statistics for access logs.'),
        ]);
        $parser->addSubcommand('statistics', [
            'help' => __('Show statistics for application logs.'),
        ]);
        $parser->addSubcommand('export', [
            'help' => __('Export application logs to compressed file in JSON Lines format (one JSON encoded line per entry).'),
            'parser' => [
                'arguments' => [
                    'file' => ['help' => __('Path to output file'), 'required' => true],
                ],
                'options' => [
                    'without-changes' => ['boolean' => true, 'help' => __('Do not include add, edit or delete actions.')],
                ],
            ],
        ]);
        $parser->addSubcommand('recompress', [
            'help' => __('Recompress compressed data in logs.'),
        ]);
        $dryRun = ['boolean' => true, 'help' => __('Count eligible rows without deleting them.')];
        $timezone = ['help' => __('IANA timezone used to write historical audit and application logs (for example UTC).')];
        foreach ([
            'auditLogRetention' => __('Audit logs'),
            'accessLogRetention' => __('Access logs'),
            'applicationLogRetention' => __('Application logs'),
        ] as $command => $category) {
            $options = ['dry-run' => $dryRun];
            if ($command !== 'accessLogRetention') {
                $options['log-timezone'] = $timezone;
            }
            $parser->addSubcommand($command, [
                'help' => __('Apply one-time retention to %s.', $category),
                'parser' => [
                    'arguments' => ['duration' => ['help' => __('Duration in days'), 'required' => true]],
                    'options' => $options,
                ],
            ]);
        }
        $parser->addSubcommand('retention', [
            'help' => __('Apply configured retention policies to all log categories.'),
            'parser' => ['options' => ['dry-run' => $dryRun, 'log-timezone' => $timezone]],
        ]);
        return $parser;
    }

    public function export()
    {
        list($path) = $this->args;
        $withoutChanges = $this->param('without-changes');

        if (file_exists($path)) {
            $this->error("File $path already exists");
        }

        $file = gzopen($path, 'wb4'); // Compression level 4 is best compromise between time and size
        if ($file === false) {
            $this->error("Could not open $path for writing");
        }

        /** @var ProgressShellHelper $progress */
        $progress = $this->helper('progress');
        $progress->init([
            'total' => $this->Log->tableRows(), // just estimate, but fast
            'width' => 50,
        ]);

        $lastId = 0;
        while (true) {
            $conditions = ['Log.id >' => $lastId]; // much faster than offset
            if ($withoutChanges) {
                $conditions['NOT'] = ['Log.action' => ['add', 'edit', 'delete']];
            }
            $logs = $this->Log->find('all', [
                'conditions' => $conditions,
                'recursive' => -1,
                'limit' => 100000,
                'order' => ['Log.id ASC'],
            ]);
            if (empty($logs)) {
                break;
            }
            $lines = '';
            foreach ($logs as $log) {
                $log = $log['Log'];
                foreach (['id', 'model_id', 'user_id'] as $field) {
                    $log[$field] = (int)$log[$field]; // Convert to int to save space
                }
                if (empty($log['description'])) {
                    unset($log['description']);
                }
                if (empty($log['ip'])) {
                    unset($log['ip']);
                }
                $log['created'] = strtotime($log['created']); // to save space
                if ($log['id'] > $lastId) {
                    $lastId = $log['id'];
                }
                $lines .= JsonTool::encode($log) . "\n";
            }
            if (gzwrite($file, $lines) === false) {
                $this->error("Could not write data to $path");
            }
            $progress->increment(count($logs));
            $progress->draw();
        }
        gzclose($file);
        $this->out('Done');
    }

    public function statistics()
    {
        $count = $this->Log->find('count');
        $first = $this->Log->find('first', [
            'recursive' => -1,
            'fields' => ['created'],
            'order' => ['id ASC'],
        ]);
        $last = $this->Log->find('first', [
            'recursive' => -1,
            'fields' => ['created'],
            'order' => ['id DESC'],
        ]);

        $this->out(str_pad(__('Count:'), 20) . $count);
        $this->out(str_pad(__('First:'), 20) . $first['Log']['created']);
        $this->out(str_pad(__('Last:'), 20) . $last['Log']['created']);

        $usage = $this->Server->dbSpaceUsage()['logs'];
        $this->out(str_pad(__('Data size:'), 20) . CakeNumber::toReadableSize($usage['data_in_bytes']));
        $this->out(str_pad(__('Index size:'), 20) . CakeNumber::toReadableSize($usage['index_in_bytes']));
        $this->out(str_pad(__('Reclaimable size:'), 20) . CakeNumber::toReadableSize($usage['reclaimable_in_bytes']), 2);
    }

    public function auditStatistics()
    {
        $count = $this->AuditLog->find('count');
        $first = $this->AuditLog->find('first', [
            'recursive' => -1,
            'fields' => ['created'],
            'order' => ['id ASC'],
        ]);
        $last = $this->AuditLog->find('first', [
            'recursive' => -1,
            'fields' => ['created'],
            'order' => ['id DESC'],
        ]);

        $this->out(str_pad(__('Count:'), 20) . $count);
        $this->out(str_pad(__('First:'), 20) . $first['AuditLog']['created']);
        $this->out(str_pad(__('Last:'), 20) . $last['AuditLog']['created']);

        $usage = $this->Server->dbSpaceUsage()['audit_logs'];
        $this->out(str_pad(__('Data size:'), 20) . CakeNumber::toReadableSize($usage['data_in_bytes']));
        $this->out(str_pad(__('Index size:'), 20) . CakeNumber::toReadableSize($usage['index_in_bytes']));
        $this->out(str_pad(__('Reclaimable size:'), 20) . CakeNumber::toReadableSize($usage['reclaimable_in_bytes']), 2);

        // Just to fetch compressionStats
        $this->AuditLog->find('column', [
            'fields' => ['change'],
        ]);

        $this->out('Change field:');
        $this->out('-------------');
        $this->out(str_pad(__('Compressed items:'), 20) . $this->AuditLog->compressionStats['compressed']);
        $this->out(str_pad(__('Total size:'), 20) . CakeNumber::toReadableSize($this->AuditLog->compressionStats['bytes_total']));
        $this->out(str_pad(__('Uncompressed size:'), 20) . CakeNumber::toReadableSize($this->AuditLog->compressionStats['bytes_uncompressed']));
        $this->out(str_pad(__('Compressed size:'), 20) . CakeNumber::toReadableSize($this->AuditLog->compressionStats['bytes_compressed']));
    }

    public function accessStatistics()
    {
        $count = $this->AccessLog->find('count');
        $first = $this->AccessLog->find('first', [
            'recursive' => -1,
            'fields' => ['created'],
            'order' => ['id ASC'],
        ]);
        $last = $this->AccessLog->find('first', [
            'recursive' => -1,
            'fields' => ['created'],
            'order' => ['id DESC'],
        ]);

        $this->out(str_pad(__('Count:'), 20) . $count);
        $this->out(str_pad(__('First:'), 20) . $first['AccessLog']['created']);
        $this->out(str_pad(__('Last:'), 20) . $last['AccessLog']['created']);

        $usage = $this->Server->dbSpaceUsage()['access_logs'];
        $this->out(str_pad(__('Data size:'), 20) . CakeNumber::toReadableSize($usage['data_in_bytes']));
        $this->out(str_pad(__('Index size:'), 20) . CakeNumber::toReadableSize($usage['index_in_bytes']));
        $this->out(str_pad(__('Reclaimable size:'), 20) . CakeNumber::toReadableSize($usage['reclaimable_in_bytes']), 2);
    }

    public function recompress()
    {
        $this->AuditLog->recompress();
    }

    public function accessLogRetention()
    {
        // Preserve the original numeric validation and relative-date parser.
        $duration = $this->args[0] ?? null;
        if ($duration <= 0 || !is_numeric($duration)) {
            return $this->error(__('Invalid duration specified.'));
        }
        try {
            $cutoff = new DateTimeImmutable("-$duration days");
            $result = LogRetentionService::runExplicit($this->AccessLog, $duration, (bool)$this->param('dry-run'), null, $cutoff);
            if (!$result['dryRun']) {
                $deleted = $result['affected'];
                $this->out(__n("Deleted %s entry", "Deleted %s entries", $deleted, $deleted));
            }
            $this->reportRetention('access_logs', $result);
            return true;
        } catch (Throwable $e) {
            return $this->retentionError('access_logs', $e);
        }
    }

    public function auditLogRetention()
    {
        return $this->explicitRetention($this->AuditLog, 'audit_logs');
    }

    public function applicationLogRetention()
    {
        return $this->explicitRetention($this->Log, 'logs');
    }

    public function retention()
    {
        try {
            $timezone = $this->retentionTimezone();
        } catch (Throwable $e) {
            return $this->retentionError('retention', $e);
        }
        $success = true;
        foreach (['audit_logs' => $this->AuditLog, 'access_logs' => $this->AccessLog, 'logs' => $this->Log] as $category => $model) {
            try {
                $result = LogRetentionService::runConfigured($model, $this->retentionConfigFile(), (bool)$this->param('dry-run'), $timezone);
                $this->reportRetention($category, $result);
                if (!in_array($result['status'], ['complete', 'disabled'], true)) {
                    $success = false;
                }
            } catch (Throwable $e) {
                // An uncertain transaction or lock outcome must terminate the
                // command rather than reusing the datasource for another table.
                return $this->retentionError($category, $e);
            }
        }
        return $success;
    }

    protected function retentionConfigFile(): string
    {
        return APP . 'Config' . DIRECTORY_SEPARATOR . 'config.php';
    }

    private function explicitRetention($model, string $category)
    {
        try {
            $days = LogRetentionPolicy::parse($this->args[0] ?? null);
            $result = LogRetentionService::runExplicit($model, $days, (bool)$this->param('dry-run'), $this->retentionTimezone(), null, $this->retentionConfigFile());
            $this->reportRetention($category, $result);
            return true;
        } catch (Throwable $e) {
            return $this->retentionError($category, $e);
        }
    }

    private function retentionTimezone(): ?DateTimeZone
    {
        $timezone = $this->param('log-timezone');
        if ($timezone === null || $timezone === false || $timezone === '') {
            return null;
        }
        if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException(__('Specify a valid IANA log writer timezone.'));
        }
        return new DateTimeZone($timezone);
    }

    private function reportRetention(string $category, array $result): void
    {
        $this->out(__('%s: mode=%s status=%s days=%s cutoff=%s batches=%s %s=%s duration=%s seconds',
            $category, $result['dryRun'] ? 'dry-run' : 'delete', $result['status'], $result['days'],
            $result['cutoff'] === null ? '-' : $result['cutoff'] . ' (' . $result['timezone'] . ')',
            $result['batches'], $result['dryRun'] ? 'eligible' : 'deleted', $result['affected'],
            number_format($result['durationSeconds'], 3, '.', '')
        ));
        if ($result['reason'] !== null) {
            $this->out(__('Reason: %s', $result['reason']));
        }
        if (isset($result['cursor'], $result['highWaterMark'])) {
            $this->out(__('Processed through ID %s of %s.', $result['cursor'], $result['highWaterMark']));
        }
    }

    private function retentionError(string $category, Throwable $error)
    {
        // Preserve diagnostic information for both operation and cleanup errors.
        $this->reportRetentionException($error);
        return $this->error(__('%s retention failed; previously committed batches are not rolled back.', $category));
    }

    private function reportRetentionException(Throwable $error): void
    {
        $this->err(get_class($error) . ': ' . $error->getMessage());
        if ($error instanceof LogRetentionCleanupException) {
            $this->reportRetentionException($error->getCleanupFailure());
        }
        if ($error->getPrevious() !== null) {
            $this->reportRetentionException($error->getPrevious());
        }
    }
}
