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
     * or waitlisted listener participation in this edition. A minor (EVT-04)
     * is stored with the guardian consent time and without a job position.
     * REG-12: with $seats set, an in-person listener beyond that number of
     * active in-person listeners joins the waiting list (status "waitlisted").
     *
     * @param array{userId: int, email: string, givenName: array<string, string>, familyName: array<string, string>,
     *   affiliation: ?string, country: ?string, orcid: ?string, position: ?string} $person
     * @return array{participantId: int, participationId: int, status: string}|null
     */
    public function addListener(
        int $contextId,
        int $editionId,
        array $person,
        ?int $categoryId,
        ParticipationForm $form,
        string $consentVersion,
        DateTimeImmutable $now,
        bool $minor = false,
        ?int $seats = null,
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
     *   role: string, status: string, submissionId: ?int, participationForm: string}|null
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

    /**
     * The payer of an invoice: name in the locale, email, affiliation, category.
     *
     * @return array{participantId: int, editionId: int, userId: ?int, name: string, email: string, affiliation: string, categoryId: ?int,
     *     details: array<string, string>}|null details: kind, organization, legalId, taxId (JUR-05)
     */
    public function payer(int $participantId, string $locale): ?array;

    /**
     * JUR-05: payer details of the participant; empty values are removed.
     *
     * @param array<string, string> $details
     */
    public function setPayerDetails(int $participantId, array $details): void;

    /** Active presenter participation of a submission (FEE-04). */
    public function presenterOf(int $submissionId): ?int;

    /**
     * REG-10: the manager invites a person. The participant is found by the
     * email in the edition or created, marked invited, and gets an active
     * participation in the role unless they already have one.
     *
     * @param array{email: string, givenName: array<string, string>, familyName: array<string, string>, affiliation: ?string, userId: ?int} $person
     * @return array{participantId: int, participationId: int, created: bool}
     */
    public function invite(int $contextId, int $editionId, array $person, ParticipantRole $role, ?int $actorId, DateTimeImmutable $now): array;

    /** REG-10: the participant was invited by the organizers and pays nothing. */
    public function isInvited(int $participantId): bool;

    /** The participant of an email in the edition, if any. */
    public function findByEmail(int $editionId, string $email): ?int;
}
