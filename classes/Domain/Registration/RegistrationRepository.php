<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

use DateTimeImmutable;

interface RegistrationRepository
{
    public function findBySubmission(int $submissionId): ?SpeakerRegistration;

    public function save(SpeakerRegistration $registration): void;

    /** @return list<SpeakerRegistration> submitted registrations of the edition */
    public function registeredOfEdition(int $editionId): array;

    /**
     * Creates or updates the participant of the edition with this email
     * and adds a participation for the submission (idempotent).
     * $confirmed: the person confirmed themselves (the submitter); others
     * stay "pending" until they answer the letter.
     *
     * @return array{participantId: int, isNew: bool, confirmationStatus: ConfirmationStatus}
     */
    public function upsertParticipant(
        int $contextId,
        int $editionId,
        AuthorInfo $author,
        ParticipantRole $role,
        ?ParticipationForm $form,
        int $submissionId,
        ?string $consentVersion,
        ?DateTimeImmutable $consentAt,
        DateTimeImmutable $now,
        bool $confirmed = false,
    ): array;

    public function setParticipantCategory(int $participantId, int $categoryId): void;

    /** EVT-04: the participant is under 18, with the legal representative's consent. */
    public function setParticipantMinor(int $participantId, ?DateTimeImmutable $guardianConsentAt): void;

    public function setConfirmationToken(int $participantId, string $hash, DateTimeImmutable $expiresAt): void;

    /**
     * @return array{participantId: int, contextId: int, editionId: int, email: string, status: ConfirmationStatus, expiresAt: ?DateTimeImmutable}|null
     */
    public function findByTokenHash(string $hash): ?array;

    /** Records the answer and invalidates the token (one-time link). */
    public function confirm(int $participantId, ConfirmationStatus $status, DateTimeImmutable $now): void;

    /**
     * @return list<array{participantId: int, email: string, role: string, confirmationStatus: string, participationForm: string}>
     */
    public function participantsOfSubmission(int $submissionId): array;
}
