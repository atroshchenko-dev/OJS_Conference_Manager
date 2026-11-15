<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Tasks;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

/**
 * DAT-02: removes receipt scans and anonymises participants after the
 * retention periods the journal set (both off by default).
 */
class RetentionTask extends ScheduledTask
{
    use RecordsRun;

    public function getName(): string
    {
        return __('plugins.generic.conferenceManager.task.retention');
    }

    protected function executeActions(): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $gateway = new ContextGateway();
        $proofs = 0;
        $people = 0;
        foreach (Application::getContextDAO()->getAll(true)->toIterator() as $journal) {
            $id = (int) $journal->getId();
            $plugin = PluginRegistry::getPlugin('generic', 'conferencemanagerplugin');
            if ($plugin === null || !$plugin->getSetting($id, 'enabled')) {
                continue;
            }
            $info = $gateway->info($id);
            if ($info === null) {
                continue;
            }
            try {
                $done = ServiceFactory::personalData()->runRetention($info, $now);
                $proofs += $done['proofs'];
                $people += $done['participants'];
            } catch (\Throwable $e) {
                $this->addExecutionLogEntry($e->getMessage(), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
            }
        }
        if ($proofs + $people > 0) {
            $this->addExecutionLogEntry(__('plugins.generic.conferenceManager.task.retention.done', ['proofs' => $proofs, 'participants' => $people]), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
        }
        return true;
    }
}
