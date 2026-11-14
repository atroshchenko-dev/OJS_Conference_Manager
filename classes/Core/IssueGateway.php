<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\conferenceManager\classes\Application\IssueProvisioner;

/**
 * Creates OJS issues for editions in the "ojs_issue" mode (EDN-04),
 * the same way the issue form of OJS 3.5 does.
 */
final class IssueGateway implements IssueProvisioner
{
    public function createUnpublishedIssue(int $contextId, array $title, ?int $year): int
    {
        $context = \APP\core\Application::getContextDAO()->getById($contextId);
        $issue = Repo::issue()->newDataObject();
        $issue->setJournalId($contextId);
        $mode = $context?->getData('publishingMode');
        $issue->setAccessStatus(
            $mode === Journal::PUBLISHING_MODE_SUBSCRIPTION || $mode === Journal::PUBLISHING_MODE_NONE
                ? Issue::ISSUE_ACCESS_SUBSCRIPTION
                : Issue::ISSUE_ACCESS_OPEN,
        );
        $issue->setData('title', $title);
        $issue->setYear($year);
        $issue->setShowVolume(0);
        $issue->setShowNumber(0);
        $issue->setShowYear($year === null ? 0 : 1);
        $issue->setShowTitle(1);
        $issue->setPublished(0);
        return (int) Repo::issue()->add($issue);
    }

    public function exists(int $contextId, int $issueId): bool
    {
        return Repo::issue()->exists($issueId, $contextId);
    }
}
