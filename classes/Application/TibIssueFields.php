<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * EDN-10: the conference fields that the TIB Conference plugin keeps on an
 * OJS issue (Domain\Edition\TibFields). Implemented by Core\TibGateway,
 * which reads and writes them by their names only.
 */
interface TibIssueFields
{
    /** True when TIB Conference is enabled in the context and its fields exist. */
    public function enabled(int $contextId): bool;

    /**
     * Writes the fields that differ; other fields of the issue stay.
     *
     * @param array<string, string> $fields
     */
    public function write(int $contextId, int $issueId, array $fields): void;
}
