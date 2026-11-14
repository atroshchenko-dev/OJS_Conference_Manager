<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Jurisdiction\Generic;

use APP\plugins\generic\conferenceManager\classes\Jurisdiction\AbstractJurisdictionProfile;

/**
 * No national fields at all. Used outside Ukraine or when an organizer
 * does not want national documents.
 */
final class GenericProfile extends AbstractJurisdictionProfile
{
    public const CODE = 'generic';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getNameKey(): string
    {
        return 'plugins.generic.conferenceManager.jurisdiction.generic';
    }

    public function getLegalIdLabelKey(): string
    {
        return 'plugins.generic.conferenceManager.institution.legalId';
    }

    public function validateLegalId(string $legalId): ?string
    {
        return mb_strlen($legalId) <= 32 ? null : self::K . 'tooLong';
    }

    public function getDefaults(): array
    {
        return ['timezone' => null, 'currency' => null, 'documentLocale' => null];
    }
}
