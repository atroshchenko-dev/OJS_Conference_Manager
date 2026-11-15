<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Jurisdiction;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\JurisdictionRules;

/**
 * A switchable set of national rules (section 7.8 of the plan).
 * The plugin core knows nothing about any country; everything national
 * comes from a profile. New profiles can be added by other plugins through
 * the "ConferenceManager::jurisdictions" hook.
 */
interface JurisdictionProfile extends JurisdictionRules
{
    public const FEATURE_TREASURY = 'treasury';
    public const FEATURE_VECTOR = 'vector';
    public const FEATURE_PK_CERTIFICATE = 'pkCertificate';
    /** Payer instructions warn that paying "to a card number" is not a transfer to the account (FEE-14). */
    public const FEATURE_CARD_WARNING = 'cardPaymentWarning';

    public function getNameKey(): string;

    /** Label of the legal id field, e.g. "ЄДРПОУ" for UA. */
    public function getLegalIdLabelKey(): string;

    /** JUR-05: label of the personal tax number of a payer, e.g. "РНОКПП" for UA. */
    public function getPersonalTaxIdLabelKey(): string;

    /**
     * @return array{timezone: ?string, currency: ?string, documentLocale: ?string}
     */
    public function getDefaults(): array;

    /** @return list<string> FEATURE_* flags */
    public function getFeatures(): array;

    /** @return list<FieldDefinition> */
    public function getInstitutionExtraFields(): array;

    /**
     * Extra fields of a payment account of this kind, shown on invoices
     * (PAY-03: e.g. the budget income code of a treasury account).
     *
     * @return list<FieldDefinition>
     */
    public function getPaymentAccountExtraFields(\APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind $kind): array;
}
