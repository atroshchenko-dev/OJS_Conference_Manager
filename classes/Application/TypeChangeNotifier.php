<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Tells the authors that the presentation type changed (DEC-05).
 */
interface TypeChangeNotifier
{
    public function typeChanged(ContextInfo $context, int $submissionId, string $oldLabel, string $newLabel, ?int $editorId): void;
}
