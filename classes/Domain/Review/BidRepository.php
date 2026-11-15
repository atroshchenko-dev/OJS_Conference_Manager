<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Review;

interface BidRepository
{
    /**
     * @param list<int> $submissionIds
     * @return array<int, array<int, Bid>> submission => user => bid
     */
    public function ofSubmissions(array $submissionIds): array;

    /** A null bid removes the reviewer's bid. */
    public function save(int $submissionId, int $userId, ?Bid $bid, string $updatedAt): void;
}
