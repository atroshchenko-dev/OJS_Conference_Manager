<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/** DEC-10: reviewers of a journal and the review state of submissions in OJS. */
interface ReviewerDirectory
{
    /**
     * Users with an active reviewer role in the journal.
     *
     * @return list<array{userId: int, name: string, email: string}>
     */
    public function reviewers(int $contextId, string $locale): array;

    public function isReviewer(int $contextId, int $userId): bool;

    /**
     * What a reviewer sees to choose (no author names) and what the
     * distribution needs. roundId is the current external review round,
     * null when the submission is not in review yet; assignedNames names
     * the assigned reviewers, who may have lost the reviewer role since.
     *
     * @param list<int> $submissionIds
     * @return array<int, array{contextId: int, stageId: int, status: int, title: string, section: string, abstract: string, keywords: list<string>, roundId: ?int, assigned: list<int>, assignedNames?: array<int, string>, authors: list<int>}>
     */
    public function submissions(array $submissionIds, string $locale): array;

    /**
     * Open review assignments (not completed, declined or cancelled) of
     * the reviewers in the journal.
     *
     * @param list<int> $userIds
     * @return array<int, int>
     */
    public function loads(int $contextId, array $userIds): array;

    /**
     * Assigns a reviewer in the current round the way the editor's form
     * does: due dates of the journal, the review files of the round, the
     * task notification and the log entry. No email: the caller sends one.
     *
     * @return array{reviewId: int, responseDue: string, reviewDue: string}|null null when the round or the reviewer is gone or already assigned
     */
    public function assign(int $contextId, int $submissionId, int $roundId, int $reviewerId, int $actorId): ?array;

    /** The URL of the review page of a reviewer for a submission. */
    public function reviewUrl(int $contextId, int $submissionId): string;
}
