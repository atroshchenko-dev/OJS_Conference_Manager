<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\Iban;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\AbstractJurisdictionProfile;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\FieldDefinition;

/**
 * Ukrainian rules: ЄДРПОУ, UA IBAN, treasury accounts, «Вектор»,
 * professional development certificates. Applies to any Ukrainian
 * organizer, whatever its type or form of ownership.
 */
final class UaProfile extends AbstractJurisdictionProfile
{
    public const CODE = 'UA';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getNameKey(): string
    {
        return 'plugins.generic.conferenceManager.jurisdiction.ua';
    }

    public function getLegalIdLabelKey(): string
    {
        return 'plugins.generic.conferenceManager.jurisdiction.ua.edrpou';
    }

    public function validateLegalId(string $legalId): ?string
    {
        return Edrpou::isValid($legalId) ? null : self::K . 'edrpou';
    }

    public function validateIban(string $iban, PaymentAccountKind $kind): ?string
    {
        $iban = Iban::normalize($iban);
        if (Iban::countryCode($iban) !== 'UA' || strlen($iban) !== 29) {
            return self::K . 'ibanUa';
        }
        return Iban::isValid($iban) ? null : self::K . 'iban';
    }

    public function allowedAccountKinds(): array
    {
        return [PaymentAccountKind::Bank, PaymentAccountKind::Treasury, PaymentAccountKind::Partner];
    }

    public function getDefaults(): array
    {
        return ['timezone' => 'Europe/Kyiv', 'currency' => 'UAH', 'documentLocale' => 'uk'];
    }

    public function getFeatures(): array
    {
        return [self::FEATURE_TREASURY, self::FEATURE_VECTOR, self::FEATURE_PK_CERTIFICATE];
    }

    public function getInstitutionExtraFields(): array
    {
        return [
            new FieldDefinition(
                'edeboId',
                'plugins.generic.conferenceManager.jurisdiction.ua.edeboId',
                descriptionKey: 'plugins.generic.conferenceManager.jurisdiction.ua.edeboId.description',
                maxLength: 16,
            ),
        ];
    }

    public function validateInstitutionExtra(array $extra): ValidationErrors
    {
        $errors = parent::validateInstitutionExtra($extra);
        $edebo = trim((string) ($extra['edeboId'] ?? ''));
        if ($edebo !== '' && !preg_match('/^\d{1,16}$/', $edebo)) {
            $errors->add('edeboId', self::K . 'digits');
        }
        return $errors;
    }
}
