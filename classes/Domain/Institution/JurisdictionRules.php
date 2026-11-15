<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

/**
 * What the domain needs from a jurisdiction to validate a profile.
 * Implemented by classes\Jurisdiction\JurisdictionProfile.
 */
interface JurisdictionRules
{
    public function getCode(): string;

    /** Whether the institution legal id (e.g. ЄДРПОУ in UA) is required. */
    public function isLegalIdRequired(): bool;

    /** Locale key of an error, or null when the id is valid. */
    public function validateLegalId(string $legalId): ?string;

    /** JUR-05: locale key of an error, or null when the personal tax number (e.g. РНОКПП in UA) is valid. */
    public function validatePersonalTaxId(string $taxId): ?string;

    /** Locale key of an error, or null when the IBAN is acceptable. */
    public function validateIban(string $iban, PaymentAccountKind $kind): ?string;

    /** @return list<PaymentAccountKind> */
    public function allowedAccountKinds(): array;

    /**
     * Validates jurisdiction-specific fields of the profile.
     *
     * @param array<string, scalar|null> $extra
     */
    public function validateInstitutionExtra(array $extra): ValidationErrors;
}
