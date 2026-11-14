<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Creates the OJS issue of an edition in the "ojs_issue" publication
 * mode (EDN-04). Implemented by Core\IssueGateway.
 */
interface IssueProvisioner
{
    /**
     * Creates an unpublished issue and returns its id.
     *
     * @param array<string, string> $title localized title
     */
    public function createUnpublishedIssue(int $contextId, array $title, ?int $year): int;

    /** True when the issue exists in the context. */
    public function exists(int $contextId, int $issueId): bool;
}
