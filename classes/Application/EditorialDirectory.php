<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Editorial state of OJS submissions for decisions (implemented in Core).
 */
interface EditorialDirectory
{
    public const STAGE_SUBMISSION = 1;
    public const STAGE_EDITING = 4;
    public const STAGE_PRODUCTION = 5;
    public const STATUS_QUEUED = 1;
    public const STATUS_DECLINED = 4;

    /**
     * @param list<int> $submissionIds
     * @return array<int, array{contextId: int, stageId: int, status: int, title: string, section: string, authors: string}>
     */
    public function summaries(array $submissionIds, string $locale): array;

    /**
     * Submissions on which the user may record a decision in their current
     * stage: assigned as an editor without "recommend only".
     *
     * @param list<int> $submissionIds
     * @return list<int>
     */
    public function decidableBy(int $userId, array $submissionIds): array;

    /** Puts the current publication in the issue unless it is already in one. */
    public function assignIssue(int $submissionId, int $issueId): bool;

    /** Writes presentationType to the current publication. */
    public function setPresentationType(int $submissionId, string $presentationType): void;

    /**
     * COM-02, COM-03: whether the authors sent a file after the acceptance
     * (the full text): any submission file created after the last
     * acceptance decision, except notes and discussion files.
     *
     * @param list<int> $submissionIds
     * @return array<int, bool>
     */
    public function hasFullText(array $submissionIds): array;
}
