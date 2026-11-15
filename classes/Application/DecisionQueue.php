<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Puts one item of a bulk decision run in the OJS job queue (Core).
 */
interface DecisionQueue
{
    public function push(int $contextId, int $batchId, int $submissionId): void;
}
