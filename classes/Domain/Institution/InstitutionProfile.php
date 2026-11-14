<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;

/**
 * Organizer profile of one OJS context (INS-01, INS-05, INS-06).
 *
 * Nothing here is specific to a particular institution: every installation
 * fills its own profile. Jurisdiction-specific fields go to $extra and are
 * described and validated by the jurisdiction profile.
 */
final class InstitutionProfile
{
    /** Localized fields stored in conf_institution_settings. */
    public const LOCALIZED_FIELDS = [
        'name',
        'shortName',
        'address',
        'privacyNotice',
        'privacyContact',
        'retentionNotice',
    ];

    /**
     * @param array<string, LocalizedText> $localized
     * @param array<string, scalar|null> $extra
     * @param list<Signer> $signers
     * @param list<PaymentAccount> $paymentAccounts
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $contextId,
        public readonly string $jurisdiction,
        public readonly InstitutionType $type,
        public readonly Ownership $ownership,
        public readonly ?string $legalId = null,
        public readonly array $localized = [],
        public readonly array $extra = [],
        public readonly string $contactEmail = '',
        public readonly string $website = '',
        public readonly ?string $logoPath = null,
        public readonly ?string $sealPath = null,
        public readonly array $signers = [],
        public readonly array $paymentAccounts = [],
    ) {
    }

    public function text(string $field): LocalizedText
    {
        return $this->localized[$field] ?? new LocalizedText();
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->contextId,
            $this->jurisdiction,
            $this->type,
            $this->ownership,
            $this->legalId,
            $this->localized,
            $this->extra,
            $this->contactEmail,
            $this->website,
            $this->logoPath,
            $this->sealPath,
            $this->signers,
            $this->paymentAccounts,
        );
    }

    /**
     * First signer assigned to the document type, if any.
     */
    public function signerFor(DocumentType $type): ?Signer
    {
        foreach ($this->signers as $signer) {
            if ($signer->signs($type)) {
                return $signer;
            }
        }
        return null;
    }

    /**
     * @return list<PaymentAccount>
     */
    public function activePaymentAccounts(): array
    {
        return array_values(array_filter($this->paymentAccounts, static fn (PaymentAccount $a): bool => $a->isActive));
    }

    /**
     * Portable representation (INS-07). Ids and file paths are left out
     * on export because they belong to the source installation.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $portable = false): array
    {
        $localized = [];
        foreach (self::LOCALIZED_FIELDS as $field) {
            $localized[$field] = $this->text($field)->toArray();
        }
        $signers = array_map(static function (Signer $s) use ($portable): array {
            $a = $s->toArray();
            if ($portable) {
                unset($a['id'], $a['signaturePath']);
            }
            return $a;
        }, $this->signers);
        $accounts = array_map(static function (PaymentAccount $p) use ($portable): array {
            $a = $p->toArray();
            if ($portable) {
                unset($a['id']);
            }
            return $a;
        }, $this->paymentAccounts);

        $data = [
            'id' => $this->id,
            'contextId' => $this->contextId,
            'jurisdiction' => $this->jurisdiction,
            'institutionType' => $this->type->value,
            'ownership' => $this->ownership->value,
            'legalId' => $this->legalId,
            'contactEmail' => $this->contactEmail,
            'website' => $this->website,
            'logoPath' => $this->logoPath,
            'sealPath' => $this->sealPath,
            'extra' => $this->extra,
        ] + $localized + [
            'signers' => $signers,
            'paymentAccounts' => $accounts,
        ];

        if ($portable) {
            unset($data['id'], $data['contextId'], $data['logoPath'], $data['sealPath']);
        }
        return $data;
    }

    /**
     * Builds a profile from user / API / import data. Unknown enum values fall
     * back to "other" / "state" and are reported by the validator through
     * the raw values it receives separately.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(int $contextId, array $data, ?int $id = null): self
    {
        $localized = [];
        foreach (self::LOCALIZED_FIELDS as $field) {
            $localized[$field] = new LocalizedText((array) ($data[$field] ?? []));
        }
        $extra = [];
        foreach ((array) ($data['extra'] ?? []) as $k => $v) {
            if (is_string($k) && (is_scalar($v) || $v === null)) {
                $extra[$k] = is_string($v) ? trim($v) : $v;
            }
        }
        $legalId = trim((string) ($data['legalId'] ?? ''));

        return new self(
            $id,
            $contextId,
            (string) ($data['jurisdiction'] ?? 'generic'),
            InstitutionType::tryFrom((string) ($data['institutionType'] ?? '')) ?? InstitutionType::Other,
            Ownership::tryFrom((string) ($data['ownership'] ?? '')) ?? Ownership::State,
            $legalId === '' ? null : $legalId,
            $localized,
            $extra,
            trim((string) ($data['contactEmail'] ?? '')),
            trim((string) ($data['website'] ?? '')),
            self::nullableString($data['logoPath'] ?? null),
            self::nullableString($data['sealPath'] ?? null),
            array_values(array_map(
                static fn (mixed $s): Signer => Signer::fromArray((array) $s),
                array_filter((array) ($data['signers'] ?? []), 'is_array'),
            )),
            array_values(array_map(
                static fn (mixed $p): PaymentAccount => PaymentAccount::fromArray((array) $p),
                array_filter((array) ($data['paymentAccounts'] ?? []), 'is_array'),
            )),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
