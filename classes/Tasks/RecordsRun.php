<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Tasks;

use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;

/**
 * DST-04: OJS 3.5 keeps no record of when a scheduled task last ran, so the
 * tasks of the plugin note it in a site-level setting for the overview.
 */
trait RecordsRun
{
    public function execute(): bool
    {
        $ok = parent::execute();
        try {
            $settings = ServiceFactory::settings();
            $runs = $settings->get(0, 'taskRuns');
            $runs = is_array($runs) ? $runs : [];
            $runs[(new \ReflectionClass($this))->getShortName()] = ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'ok' => $ok];
            $settings->set(0, 'taskRuns', $runs);
        } catch (\Throwable) {
            // The record is a convenience; the task itself has run.
        }
        return $ok;
    }
}
