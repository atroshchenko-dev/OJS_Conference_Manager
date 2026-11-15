<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionWindows;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\AuthorInfo;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationToken;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationValidator;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\SpeakerRegistration;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateInterval;
use DateTimeImmutable;

/**
 * The "Conference participation" step of the submission wizard and what
 * happens after submitting (REG-02, REG-03, REG-04, EDN-03).
 */
final class SubmissionRegistrationService
{
    public const RESULT_CONFIRMED = 'confirmed';
    public const RESULT_DECLINED = 'declined';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_EXPIRED = 'expired';

    private const K = 'plugins.generic.conferenceManager.registration.error.';

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionRepository $editions,
        private readonly SubmissionDirectory $submissions,
        private readonly ?ParticipantNotifier $notifier = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Editions the wizard offers: the call is open now.
     *
     * @return list<Edition>
     */
    public function openEditions(ContextInfo $context, DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->editions->list($context->id, [EditionStatus::CfpOpen], 100),
            static fn (Edition $e): bool => EditionWindows::submissionsOpenAt($e, $now),
        ));
    }

    /**
     * The step is shown while a call is open, and for a submission that
     * already chose an edition (so the author sees why it is blocked).
     */
    public function isStepNeeded(ContextInfo $context, int $submissionId, DateTimeImmutable $now): bool
    {
        return $this->openEditions($context, $now) !== []
            || $this->registrations->findBySubmission($submissionId)?->editionId !== null;
    }

    public function get(int $submissionId): SpeakerRegistration
    {
        return $this->registrations->findBySubmission($submissionId) ?? new SpeakerRegistration($submissionId);
    }

    /**
     * Autosave of the wizard. Incomplete answers are kept: the full
     * check happens on submit. Returns the saved questionnaire and
     * warnings, or null when nothing may be changed any more.
     *
     * @param array<string, mixed> $input editionId, presentationType, participationForm, presenterEmail, consent
     * @return array{0: ?SpeakerRegistration, 1: ValidationErrors}
     */
    public function save(
        ContextInfo $context,
        SubmissionInfo $submission,
        array $input,
        string $consentVersion,
        ?int $userId,
        DateTimeImmutable $now,
        bool $isManager = false,
    ): array {
        $errors = new ValidationErrors();
        if ($submission->submitted && !$isManager) {
            $errors->add(RegistrationValidator::FIELD_EDITION, self::K . 'alreadySubmitted');
            return [null, $errors];
        }
        $current = $this->get($submission->id);
        if ($current->isRegistered()) {
            $errors->add(RegistrationValidator::FIELD_EDITION, self::K . 'alreadySubmitted');
            return [null, $errors];
        }
        $next = $current->withInput($input, $consentVersion, $now, $userId);
        if ($next->editionId !== null && $next->editionId !== $current->editionId) {
            $edition = $this->editions->find($context->id, $next->editionId);
            if ($edition === null || !EditionWindows::submissionsOpenAt($edition, $now)) {
                // The other answers are still kept: the wizard treats a
                // failed autosave as a lost connection.
                $errors->add(RegistrationValidator::FIELD_EDITION, self::K . 'callClosed');
                $next = $next->withInput(['editionId' => $current->editionId], $consentVersion, $now, $userId);
            }
        }
        $this->registrations->save($next);
        return [$next, $errors];
    }

    /**
     * Run by OJS before a submission is submitted (hook Submission::validateSubmit).
     */
    public function validateSubmit(
        ContextInfo $context,
        int $submissionId,
        string $consentVersion,
        DateTimeImmutable $now,
        bool $regularAllowed = false,
    ): ValidationErrors {
        if (!$this->isStepNeeded($context, $submissionId, $now)) {
            return new ValidationErrors();
        }
        $registration = $this->registrations->findBySubmission($submissionId);
        $edition = $registration?->editionId === null ? null : $this->editions->find($context->id, $registration->editionId);
        return (new RegistrationValidator())->validate(
            $registration,
            $edition,
            $this->submissions->authors($submissionId),
            $consentVersion,
            $now,
            $regularAllowed,
        );
    }

    /**
     * After submitting (REG-03, REG-04): every author becomes a participant
     * of the edition, the presenter is marked, the publication is linked to
     * the edition and co-authors get a letter with a one-time link.
     * Running it twice changes nothing.
     *
     * @return int number of letters sent
     */
    public function onSubmitted(ContextInfo $context, int $submissionId, ?int $submitterUserId, DateTimeImmutable $now): int
    {
        $registration = $this->registrations->findBySubmission($submissionId);
        if ($registration === null || $registration->editionId === null || $registration->isRegistered()) {
            return 0;
        }
        $edition = $this->editions->find($context->id, $registration->editionId);
        $submission = $this->submissions->find($submissionId);
        if ($edition === null || $submission === null || $submission->contextId !== $context->id) {
            return 0;
        }

        $submitterEmail = $submitterUserId === null ? null : $this->submissions->userEmail($submitterUserId);
        $submitterEmail = $submitterEmail === null ? null : mb_strtolower(trim($submitterEmail));
        $authors = $this->submissions->authors($submissionId);
        $submitterName = '';
        foreach ($authors as $author) {
            if ($author->normalizedEmail() === $submitterEmail) {
                $submitterName = $author->displayName($submission->locale ?: $context->primaryLocale);
            }
        }

        $expiresAt = $now->add(new DateInterval('P' . ConfirmationToken::LIFETIME_DAYS . 'D'));
        $letters = [];
        $created = [];
        foreach ($authors as $author) {
            $isSubmitter = $submitterEmail !== null && $author->normalizedEmail() === $submitterEmail;
            $role = $author->normalizedEmail() === $registration->presenterEmail ? ParticipantRole::Presenter : ParticipantRole::Coauthor;
            $result = $this->registrations->upsertParticipant(
                $context->id,
                $edition->id ?? 0,
                $author,
                $role,
                $registration->participationForm,
                $submissionId,
                $isSubmitter ? $registration->consentVersion : null,
                $isSubmitter ? $registration->consentAt : null,
                $now,
                $isSubmitter,
            );
            $created[] = ['participantId' => $result['participantId'], 'role' => $role->value, 'isNew' => $result['isNew']];
            if ($result['confirmationStatus'] === ConfirmationStatus::Pending) {
                [$token, $hash] = ConfirmationToken::generate();
                $this->registrations->setConfirmationToken($result['participantId'], $hash, $expiresAt);
                $letters[] = $this->letter($author, $token, $expiresAt, $edition, $submission, $submitterName, $role, $context);
            }
        }

        $this->submissions->linkToEdition($submissionId, $edition->id ?? 0, $registration->presentationType?->value);
        $this->registrations->save($registration->withRegisteredAt($now));
        $this->audit?->log($context->id, $submitterUserId, 'submission_registration', $submissionId, 'registered', null, [
            'editionId' => $edition->id,
            'participants' => $created,
        ]);

        $sent = 0;
        foreach ($letters as $letter) {
            try {
                $this->notifier?->requestConfirmation($context, $letter);
                $sent++;
            } catch (\Throwable $e) {
                // A failed letter must not undo the submission; the
                // registration manager sees "pending" and can resend later.
                error_log('conferenceManager: confirmation letter to participant failed: ' . $e->getMessage());
            }
        }
        return $sent;
    }

    /**
     * What the confirmation page shows before the person answers.
     *
     * @return array{result: string, editionId?: int, email?: string}
     */
    public function inspectToken(int $contextId, string $token, DateTimeImmutable $now): array
    {
        $row = ConfirmationToken::looksValid($token) ? $this->registrations->findByTokenHash(ConfirmationToken::hash($token)) : null;
        if ($row === null || $row['contextId'] !== $contextId || $row['status'] !== ConfirmationStatus::Pending) {
            return ['result' => self::RESULT_INVALID];
        }
        if ($row['expiresAt'] !== null && $row['expiresAt'] <= $now) {
            return ['result' => self::RESULT_EXPIRED, 'editionId' => $row['editionId']];
        }
        return ['result' => 'pending', 'editionId' => $row['editionId'], 'email' => $row['email']];
    }

    /**
     * The answer to the letter (REG-04). The link works once.
     *
     * @return array{result: string, editionId?: int}
     */
    public function answer(int $contextId, string $token, bool $accept, DateTimeImmutable $now, ?string $ip = null): array
    {
        $state = $this->inspectToken($contextId, $token, $now);
        if ($state['result'] !== 'pending') {
            return $state;
        }
        $row = $this->registrations->findByTokenHash(ConfirmationToken::hash($token));
        if ($row === null) {
            return ['result' => self::RESULT_INVALID];
        }
        $status = $accept ? ConfirmationStatus::Confirmed : ConfirmationStatus::Declined;
        $this->registrations->confirm($row['participantId'], $status, $now);
        $this->audit?->log($contextId, null, 'participant', $row['participantId'], 'confirmation_' . $status->value, null, null, $ip);
        return ['result' => $accept ? self::RESULT_CONFIRMED : self::RESULT_DECLINED, 'editionId' => $row['editionId']];
    }

    /**
     * @return list<array{participantId: int, email: string, role: string, confirmationStatus: string, participationForm: string}>
     */
    public function participantsOfSubmission(int $submissionId): array
    {
        return $this->registrations->participantsOfSubmission($submissionId);
    }

    private function letter(
        AuthorInfo $author,
        string $token,
        DateTimeImmutable $expiresAt,
        Edition $edition,
        SubmissionInfo $submission,
        string $submitterName,
        ParticipantRole $role,
        ContextInfo $context,
    ): ConfirmationRequest {
        return new ConfirmationRequest(
            $author->email,
            $author->displayName($submission->locale ?: $context->primaryLocale),
            $token,
            $expiresAt,
            $edition->timezone,
            $edition->text('name')->toArray(),
            $submission->title,
            $submitterName,
            $role->value,
        );
    }
}
