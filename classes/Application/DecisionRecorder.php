<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Records one editorial decision through OJS with the default letter to
 * the authors (implemented in Core). Used by the bulk decisions.
 */
interface DecisionRecorder
{
    /** Null when recorded, else a short reason. */
    public function record(int $contextId, int $submissionId, int $decision, int $editorId): ?string;
}
