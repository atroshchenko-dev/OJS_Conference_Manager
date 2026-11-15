<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Certificate;

use DateTimeImmutable;

interface CertificateRepository
{
    /** @return array{id: int, version: int, html: string, builtIn: bool}|null the active template of the type */
    public function activeTemplate(int $contextId, string $type): ?array;

    /** Stores a new version of the template of the type and makes it the active one; null html returns to the built-in one. */
    public function saveTemplate(int $contextId, string $type, ?string $html, string $builtInHtml, DateTimeImmutable $now): int;

    /**
     * The row of the built-in template (created on first use).
     *
     * @return array{id: int, version: int}
     */
    public function builtInTemplate(int $contextId, string $type, string $html, DateTimeImmutable $now): array;

    /**
     * Issues a certificate: the number from the context counter and the record, in one transaction.
     *
     * @param array<string, mixed> $row edition_id, participant_id, template_id, template_version, type, snapshot
     * @return array{id: int, number: string, uuid: string}
     */
    public function create(int $contextId, string $series, array $row, DateTimeImmutable $now): array;

    public function setPdf(int $id, string $path, string $sha256): void;

    /** @return array<string, mixed>|null */
    public function find(int $contextId, int $id): ?array;

    /** @return array<string, mixed>|null by uuid, of any context */
    public function findByUuid(string $uuid): ?array;

    /** @return list<array<string, mixed>> */
    public function ofEdition(int $contextId, int $editionId): array;

    /**
     * @param list<int> $participantIds
     * @return list<array<string, mixed>>
     */
    public function ofParticipants(array $participantIds): array;
}
