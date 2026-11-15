<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionWindows;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use DateTimeImmutable;

/**
 * Checks of the speaker questionnaire when the submission is submitted
 * (REG-02, EDN-03). Error fields are the names of the wizard fields, so
 * OJS shows each message on the "Conference participation" step.
 */
final class RegistrationValidator
{
    private const K = 'plugins.generic.conferenceManager.registration.error.';

    public const FIELD_EDITION = 'conferenceEditionId';
    public const FIELD_PRESENTATION = 'conferencePresentationType';
    public const FIELD_FORM = 'conferenceParticipationForm';
    public const FIELD_PRESENTER = 'conferencePresenterEmail';
    public const FIELD_CONSENT = 'conferenceConsent';

    /**
     * @param list<AuthorInfo> $authors authors of the current publication
     * @param bool $regularAllowed the journal also takes submissions that are not for an edition
     */
    public function validate(
        ?SpeakerRegistration $registration,
        ?Edition $edition,
        array $authors,
        string $consentVersion,
        DateTimeImmutable $now,
        bool $regularAllowed = false,
    ): ValidationErrors {
        $errors = new ValidationErrors();
        if ($registration === null || $registration->editionId === null) {
            if (!$regularAllowed) {
                $errors->add(self::FIELD_EDITION, self::K . 'editionRequired');
            }
            return $errors;
        }
        if ($edition === null) {
            $errors->add(self::FIELD_EDITION, self::K . 'editionRequired');
            return $errors;
        }
        if (!EditionWindows::submissionsOpenAt($edition, $now)) {
            $errors->add(self::FIELD_EDITION, self::K . 'callClosed');
        }
        if ($registration->presentationType === null) {
            $errors->add(self::FIELD_PRESENTATION, self::K . 'required');
        }
        if ($registration->participationForm === null) {
            $errors->add(self::FIELD_FORM, self::K . 'required');
        }
        $emails = array_map(static fn (AuthorInfo $a): string => $a->normalizedEmail(), $authors);
        if ($registration->presenterEmail === null) {
            $errors->add(self::FIELD_PRESENTER, self::K . 'required');
        } elseif (!in_array($registration->presenterEmail, $emails, true)) {
            $errors->add(self::FIELD_PRESENTER, self::K . 'presenterNotAuthor');
        }
        if (!$registration->hasConsent()) {
            $errors->add(self::FIELD_CONSENT, self::K . 'consentRequired');
        } elseif ($registration->consentVersion !== $consentVersion) {
            $errors->add(self::FIELD_CONSENT, self::K . 'consentOutdated');
        }
        return $errors;
    }
}
