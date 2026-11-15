<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\DecisionQueue;
use APP\plugins\generic\conferenceManager\classes\Core\Jobs\RunBulkDecision;

final class OjsDecisionQueue implements DecisionQueue
{
    public function push(int $contextId, int $batchId, int $submissionId): void
    {
        dispatch(new RunBulkDecision($contextId, $batchId, $submissionId));
    }
}
