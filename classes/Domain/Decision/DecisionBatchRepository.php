<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Decision;

use DateTimeImmutable;

/**
 * Runs of bulk decisions (DEC-03) and their items.
 */
interface DecisionBatchRepository
{
    /** @param list<int> $submissionIds */
    public function create(int $contextId, int $editionId, int $decision, int $editorId, array $submissionIds, DateTimeImmutable $now): int;

    /**
     * @return array{id: int, contextId: int, editionId: int, decision: int, editorId: int, createdAt: ?string,
     *   items: list<array{submissionId: int, status: string, error: ?string}>}|null
     */
    public function find(int $contextId, int $batchId): ?array;

    /** @return list<int> ids of the runs of the edition, newest first */
    public function ofEdition(int $contextId, int $editionId, int $limit): array;

    /**
     * Marks a pending item as taken; false when it was not pending (the job
     * ran twice).
     */
    public function claim(int $batchId, int $submissionId, DateTimeImmutable $now): bool;

    public function finish(int $batchId, int $submissionId, ?string $error, DateTimeImmutable $now): void;
}
