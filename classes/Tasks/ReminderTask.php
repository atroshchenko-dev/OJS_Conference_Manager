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
 * COM-03: reminders about payment, the full text and the start of an
 * event, in every journal where the plugin is on and reminders are turned on.
 */
class ReminderTask extends ScheduledTask
{
    public function getName(): string
    {
        return __('plugins.generic.conferenceManager.task.reminders');
    }

    protected function executeActions(): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $gateway = new ContextGateway();
        $total = 0;
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
                $done = ServiceFactory::reminders()->run($info, $now);
                $total += $done['payment'] + $done['paper'] + $done['event'];
                if ($done['failed'] > 0) {
                    $this->addExecutionLogEntry(__('plugins.generic.conferenceManager.task.reminders.failed', ['count' => $done['failed']]), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
                }
            } catch (\Throwable $e) {
                $this->addExecutionLogEntry($e->getMessage(), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
            }
        }
        if ($total > 0) {
            $this->addExecutionLogEntry(__('plugins.generic.conferenceManager.task.reminders.sent', ['count' => $total]), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
        }
        return true;
    }
}
