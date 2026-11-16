<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Tasks;

use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

/**
 * PAY-09: card payments whose report has not come are asked about at the
 * gateway, so that a lost report does not leave a paid invoice open.
 */
class GatewayStatusTask extends ScheduledTask
{
    use RecordsRun;

    public function getName(): string
    {
        return __('plugins.generic.conferenceManager.task.gateway');
    }

    protected function executeActions(): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $contexts = new ContextGateway();
        $plugin = PluginRegistry::getPlugin('generic', 'conferencemanagerplugin');
        try {
            $changed = ServiceFactory::onlinePayments()->refreshOpen(
                static fn (int $id) => $plugin !== null && $plugin->getSetting($id, 'enabled') ? $contexts->info($id) : null,
                $now,
            );
            if ($changed > 0) {
                $this->addExecutionLogEntry(__('plugins.generic.conferenceManager.task.gateway.changed', ['count' => $changed]), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            }
        } catch (\Throwable $e) {
            $this->addExecutionLogEntry($e->getMessage(), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
        }
        return true;
    }
}
