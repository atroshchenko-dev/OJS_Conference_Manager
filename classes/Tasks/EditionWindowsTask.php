<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Tasks;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

/**
 * EDN-03: closes calls for papers whose abstract deadline has passed.
 * Registered by the plugin through HasTaskScheduler (OJS 3.5 scheduler,
 * run by cron "php lib/pkp/tools/scheduler.php run" or by the web runner).
 */
class EditionWindowsTask extends ScheduledTask
{
    public function getName(): string
    {
        return __('plugins.generic.conferenceManager.task.editionWindows');
    }

    protected function executeActions(): bool
    {
        $closed = ServiceFactory::editions()->closeExpiredCalls(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if ($closed > 0) {
            $this->addExecutionLogEntry(
                __('plugins.generic.conferenceManager.task.editionWindows.closed', ['count' => $closed]),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE,
            );
        }
        return true;
    }
}
