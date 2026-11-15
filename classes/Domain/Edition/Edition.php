<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\Isbn;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use DateTimeImmutable;

/**
 * One event of a context: a conference, seminar, webinar… (plan 8.2, EDN, EVT).
 * Dates are UTC; $timezone is the IANA zone the event is held in.
 */
final class Edition
{
    public const LOCALIZED_FIELDS = ['name', 'description', 'venue', 'learningOutcomes', 'cfpText', 'partialQualifications'];

    /** API field => database column, for every date of the edition. */
    public const DATE_FIELDS = [
        'startsAt' => 'starts_at',
        'endsAt' => 'ends_at',
        'cfpOpensAt' => 'cfp_opens_at',
        'abstractDeadline' => 'abstract_deadline',
        'paperDeadline' => 'paper_deadline',
        'registrationClosesAt' => 'registration_closes_at',
        'paymentDeadline' => 'payment_deadline',
    ];

    private const K = 'plugins.generic.conferenceManager.validation.';

    /**
     * @param array<string, ?DateTimeImmutable> $dates keys of DATE_FIELDS
     * @param array<string, LocalizedText> $texts keys of LOCALIZED_FIELDS
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $contextId,
        public readonly string $path,
        public readonly EditionStatus $status,
        public readonly EventType $eventType,
        public readonly SubmissionMode $submissionMode,
        public readonly PublicationMode $publicationMode,
        public readonly ?string $externalProceedingsUrl,
        public readonly EventFormat $format,
        public readonly string $timezone,
        private readonly array $dates,
        public readonly ReviewMode $reviewMode,
        public readonly ?string $isbn = null,
        public readonly ?string $udc = null,
        public readonly ?string $vectorProgramId = null,
        public readonly ?int $hours = null,
        public readonly ?int $ectsX100 = null,
        public readonly ?int $issueId = null,
        private readonly array $texts = [],
    ) {
    }

    public function date(string $field): ?DateTimeImmutable
    {
        return $this->dates[$field] ?? null;
    }

    public function text(string $field): LocalizedText
    {
        return $this->texts[$field] ?? new LocalizedText();
    }

    /** @return array<string, LocalizedText> */
    public function texts(): array
    {
        $out = [];
        foreach (self::LOCALIZED_FIELDS as $field) {
            $out[$field] = $this->text($field);
        }
        return $out;
    }

    public function withId(int $id): self
    {
        return $this->with(['id' => $id]);
    }

    public function withIssueId(?int $issueId): self
    {
        return $this->with(['issueId' => $issueId]);
    }

    public function withStatus(EditionStatus $status): self
    {
        return $this->with(['status' => $status]);
    }

    /**
     * Builds an edition from untrusted input (API body, form, copy).
     * Unknown choices and unreadable dates are reported in $errors and
     * replaced by defaults, so the result is always a valid object.
     * When submission or publication mode is missing, the defaults of
     * the event type apply (EVT-01).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(int $contextId, array $data, ?int $id, string $defaultTimezone, ValidationErrors $errors): self
    {
        $eventType = self::choice(EventType::class, $data, 'eventType', EventType::Conference, $errors);
        $timezone = trim((string) ($data['timezone'] ?? ''));
        if ($timezone === '') {
            $timezone = $defaultTimezone;
        } elseif (!DateInput::isValidTimezone($timezone)) {
            $errors->add('timezone', self::K . 'timezone');
            $timezone = $defaultTimezone;
        }

        $dates = [];
        foreach (array_keys(self::DATE_FIELDS) as $field) {
            $parsed = DateInput::parse($data[$field] ?? null, $timezone);
            if ($parsed === false) {
                $errors->add($field, self::K . 'date');
                $parsed = null;
            }
            $dates[$field] = $parsed;
        }

        $texts = [];
        foreach (self::LOCALIZED_FIELDS as $field) {
            $texts[$field] = new LocalizedText(is_array($data[$field] ?? null) ? $data[$field] : []);
        }

        $isbn = self::nullableString($data['isbn'] ?? null);
        $publicationMode = self::choice(PublicationMode::class, $data, 'publicationMode', $eventType->defaultPublicationMode(), $errors);

        return new self(
            $id,
            $contextId,
            strtolower(trim((string) ($data['path'] ?? ''))),
            self::choice(EditionStatus::class, $data, 'status', EditionStatus::Draft, $errors),
            $eventType,
            self::choice(SubmissionMode::class, $data, 'submissionMode', $eventType->defaultSubmissionMode(), $errors),
            $publicationMode,
            $publicationMode === PublicationMode::External ? self::nullableString($data['externalProceedingsUrl'] ?? null) : null,
            self::choice(EventFormat::class, $data, 'format', EventFormat::InPerson, $errors),
            $timezone,
            $dates,
            self::choice(ReviewMode::class, $data, 'reviewMode', ReviewMode::Light, $errors),
            $isbn === null ? null : Isbn::normalize($isbn),
            self::nullableString($data['udc'] ?? null),
            self::nullableString($data['vectorProgramId'] ?? null),
            self::nullableInt($data, 'hours', $errors),
            self::nullableInt($data, 'ectsX100', $errors),
            isset($data['issueId']) && is_numeric($data['issueId']) ? (int) $data['issueId'] : null,
            $texts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'contextId' => $this->contextId,
            'path' => $this->path,
            'status' => $this->status->value,
            'eventType' => $this->eventType->value,
            'submissionMode' => $this->submissionMode->value,
            'publicationMode' => $this->publicationMode->value,
            'externalProceedingsUrl' => $this->externalProceedingsUrl,
            'format' => $this->format->value,
            'timezone' => $this->timezone,
            'reviewMode' => $this->reviewMode->value,
            'isbn' => $this->isbn,
            'udc' => $this->udc,
            'vectorProgramId' => $this->vectorProgramId,
            'hours' => $this->hours,
            'ectsX100' => $this->ectsX100,
            'issueId' => $this->issueId,
        ];
        foreach (array_keys(self::DATE_FIELDS) as $field) {
            $out[$field] = DateInput::toIsoUtc($this->date($field));
        }
        $out['local'] = [];
        foreach (array_keys(self::DATE_FIELDS) as $field) {
            $out['local'][$field] = DateInput::toLocal($this->date($field), $this->timezone);
        }
        foreach (self::LOCALIZED_FIELDS as $field) {
            $out[$field] = $this->text($field)->toArray();
        }
        return $out;
    }

    /**
     * Input for a new edition copied from this one (EDN-09 starts here):
     * texts, modes and settings are kept; path, status, dates and the
     * OJS issue are not.
     *
     * @return array<string, mixed>
     */
    public function toCopyInput(): array
    {
        $data = $this->toArray();
        unset($data['id'], $data['issueId'], $data['local'], $data['isbn']);
        foreach (array_keys(self::DATE_FIELDS) as $field) {
            unset($data[$field]);
        }
        $data['status'] = EditionStatus::Draft->value;
        $data['path'] = '';
        return $data;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        return new self(
            array_key_exists('id', $changes) ? $changes['id'] : $this->id,
            $this->contextId,
            $this->path,
            $changes['status'] ?? $this->status,
            $this->eventType,
            $this->submissionMode,
            $this->publicationMode,
            $this->externalProceedingsUrl,
            $this->format,
            $this->timezone,
            $this->dates,
            $this->reviewMode,
            $this->isbn,
            $this->udc,
            $this->vectorProgramId,
            $this->hours,
            $this->ectsX100,
            array_key_exists('issueId', $changes) ? $changes['issueId'] : $this->issueId,
            $this->texts,
        );
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @param array<string, mixed> $data
     * @param T $default
     * @return T
     */
    private static function choice(string $enum, array $data, string $field, \BackedEnum $default, ValidationErrors $errors): \BackedEnum
    {
        $raw = $data[$field] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        $value = is_string($raw) ? $enum::tryFrom($raw) : null;
        if ($value === null) {
            $errors->add($field, self::K . 'invalidChoice');
            return $default;
        }
        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullableInt(array $data, string $field, ValidationErrors $errors): ?int
    {
        $value = $data[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)))) {
            return (int) $value;
        }
        $errors->add($field, self::K . 'integer');
        return null;
    }
}
