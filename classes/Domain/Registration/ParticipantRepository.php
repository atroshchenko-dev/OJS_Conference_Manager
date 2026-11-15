<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

use DateTimeImmutable;

/**
 * Participants and their participations (REG-05…08). Every read is bound
 * to a context id.
 */
interface ParticipantRepository
{
    /**
     * Adds a listener participation (REG-05), creating the participant when
     * needed. Returns null when the person already has an active listener
     * participation in this edition.
     *
     * @param array{userId: int, email: string, givenName: array<string, string>, familyName: array<string, string>,
     *   affiliation: ?string, country: ?string, orcid: ?string, position: ?string} $person
     * @return array{participantId: int, participationId: int}|null
     */
    public function addListener(
        int $contextId,
        int $editionId,
        array $person,
        ?int $categoryId,
        ParticipationForm $form,
        string $consentVersion,
        DateTimeImmutable $now,
    ): ?array;

    /**
     * Participations of a person in the context, found by the user id or by
     * the email (authors added before they had an account).
     *
     * @return list<array<string, mixed>>
     */
    public function ofPerson(int $contextId, int $userId, string $email): array;

    /**
     * @return array{participationId: int, participantId: int, editionId: int, userId: ?int, email: string,
     *   role: string, status: string, submissionId: ?int}|null
     */
    public function findParticipation(int $contextId, int $participationId): ?array;

    public function cancel(int $participationId, DateTimeImmutable $now): void;

    public function hasActiveParticipations(int $participantId): bool;

    /**
     * After the last participation is cancelled (REG-08): unpaid invoices of
     * the participant in the edition are cancelled, paid ones become
     * "refund_requested". Every change is written to the payment events.
     *
     * @return array{cancelled: int, refundRequested: int}
     */
    public function settleInvoices(int $participantId, int $editionId, ?int $actorId, DateTimeImmutable $now): array;

    /**
     * @return array{items: list<array<string, mixed>>, itemsMax: int}
     */
    public function register(int $contextId, int $editionId, ParticipantFilter $filter, string $locale): array;

    public function setCategory(int $participantId, ?int $categoryId): void;
}
