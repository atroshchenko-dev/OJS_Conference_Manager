<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Review;

interface VoteRepository
{
    /** @return list<array{userId: int, vote: Vote, comment: string, updatedAt: string}> */
    public function ofSubmission(int $submissionId): array;

    /**
     * @param list<int> $submissionIds
     * @return array<int, array{for: int, against: int, abstain: int}> by submission
     */
    public function counts(array $submissionIds): array;

    /** One vote of a member per submission; a new vote replaces the old one. */
    public function save(int $submissionId, int $userId, Vote $vote, string $comment, string $updatedAt): void;

    public function delete(int $submissionId, int $userId): bool;
}
