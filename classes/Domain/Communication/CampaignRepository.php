<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Communication;

use DateTimeImmutable;

/**
 * Mass mailings (COM-02): the letter and one row per recipient with its
 * delivery state (pending → sending → sent | failed).
 */
interface CampaignRepository
{
    /**
     * @param array<string, mixed> $filter
     * @param list<array{participantId: ?int, email: string, name: string}> $recipients
     */
    public function create(int $contextId, int $editionId, ?int $createdBy, string $locale, string $subject, string $body, array $filter, array $recipients, DateTimeImmutable $now): int;

    /** @return array<string, mixed>|null id, contextId, editionId, subject, body, locale, total, createdAt */
    public function find(int $contextId, int $id): ?array;

    /**
     * Takes up to $limit pending recipients for sending.
     *
     * @return list<array{id: int, participantId: ?int, email: string, name: string}>
     */
    public function claim(int $campaignId, int $limit): array;

    public function finish(int $recipientId, bool $sent, ?string $error, DateTimeImmutable $now): void;

    /** @return array<string, int> status => count */
    public function counts(int $campaignId): array;

    /** @return list<array{email: string, name: string, error: ?string}> */
    public function failed(int $campaignId, int $limit): array;

    /** @return list<array<string, mixed>> newest first */
    public function ofEdition(int $contextId, int $editionId, int $limit): array;
}
