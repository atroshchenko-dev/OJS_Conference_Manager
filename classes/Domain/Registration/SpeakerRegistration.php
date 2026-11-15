<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

use DateTimeImmutable;

/**
 * Speaker questionnaire of one submission (REG-02), filled in the
 * submission wizard. Becomes participants and participations when the
 * submission is submitted (REG-03).
 */
final class SpeakerRegistration
{
    public function __construct(
        public readonly int $submissionId,
        public readonly ?int $editionId = null,
        public readonly ?PresentationType $presentationType = null,
        public readonly ?ParticipationForm $participationForm = null,
        public readonly ?string $presenterEmail = null,
        public readonly ?string $consentVersion = null,
        public readonly ?DateTimeImmutable $consentAt = null,
        public readonly ?int $consentUserId = null,
        public readonly ?DateTimeImmutable $registeredAt = null,
        /** participant category of the presenter (FEE-01) */
        public readonly ?int $categoryId = null,
    ) {
    }

    public function hasConsent(): bool
    {
        return $this->consentVersion !== null && $this->consentAt !== null;
    }

    public function isRegistered(): bool
    {
        return $this->registeredAt !== null;
    }

    /**
     * Applies wizard input. Consent is recorded with the version of the
     * text the person saw and the moment of agreeing; unticking removes it.
     *
     * @param array<string, mixed> $input
     */
    public function withInput(array $input, string $consentVersion, DateTimeImmutable $now, ?int $userId): self
    {
        $editionId = array_key_exists('editionId', $input)
            ? (is_numeric($input['editionId']) && (int) $input['editionId'] > 0 ? (int) $input['editionId'] : null)
            : $this->editionId;
        $presentationType = array_key_exists('presentationType', $input)
            ? PresentationType::tryFrom((string) $input['presentationType'])
            : $this->presentationType;
        $participationForm = array_key_exists('participationForm', $input)
            ? ParticipationForm::tryFrom((string) $input['participationForm'])
            : $this->participationForm;
        $presenterEmail = array_key_exists('presenterEmail', $input)
            ? (trim((string) $input['presenterEmail']) === '' ? null : mb_strtolower(trim((string) $input['presenterEmail'])))
            : $this->presenterEmail;

        $categoryId = array_key_exists('categoryId', $input)
            ? (is_numeric($input['categoryId']) && (int) $input['categoryId'] > 0 ? (int) $input['categoryId'] : null)
            : $this->categoryId;

        $version = $this->consentVersion;
        $at = $this->consentAt;
        $by = $this->consentUserId;
        if (array_key_exists('consent', $input)) {
            $raw = is_array($input['consent']) ? ($input['consent'][0] ?? false) : $input['consent'];
            if (!filter_var($raw, FILTER_VALIDATE_BOOLEAN)) {
                $version = $at = $by = null;
            } elseif ($this->consentVersion !== $consentVersion || $this->consentAt === null) {
                // A new consent, or a consent to a changed text.
                $version = $consentVersion;
                $at = $now;
                $by = $userId;
            }
        }
        return new self($this->submissionId, $editionId, $presentationType, $participationForm, $presenterEmail, $version, $at, $by, $this->registeredAt, $categoryId);
    }

    public function withRegisteredAt(DateTimeImmutable $at): self
    {
        return new self($this->submissionId, $this->editionId, $this->presentationType, $this->participationForm, $this->presenterEmail, $this->consentVersion, $this->consentAt, $this->consentUserId, $at, $this->categoryId);
    }
}
