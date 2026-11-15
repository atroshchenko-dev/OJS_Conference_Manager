<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * What the plugin needs to know about an OJS submission.
 */
final class SubmissionInfo
{
    /**
     * @param array<string, string> $title locale => title of the current publication
     */
    public function __construct(
        public readonly int $id,
        public readonly int $contextId,
        public readonly bool $submitted,
        public readonly array $title = [],
        public readonly string $locale = '',
    ) {
    }
}
