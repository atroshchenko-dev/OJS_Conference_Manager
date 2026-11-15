<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Certificate;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\DocumentType;

/** Certificate kinds of CRT-01; professional development (CRT-03) only in a jurisdiction with that feature. */
enum CertificateType: string
{
    case Participation = 'participation';
    case Speaker = 'speaker';
    case Listener = 'listener';
    case Organizer = 'organizer';
    case Reviewer = 'reviewer';
    case ProfessionalDevelopment = 'pk';

    public function documentType(): DocumentType
    {
        return match ($this) {
            self::Participation => DocumentType::CertificateParticipation,
            self::Speaker => DocumentType::CertificateSpeaker,
            self::Listener => DocumentType::CertificateListener,
            self::Organizer => DocumentType::CertificateOrganizer,
            self::Reviewer => DocumentType::CertificateReviewer,
            self::ProfessionalDevelopment => DocumentType::CertificateProfessionalDevelopment,
        };
    }

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.certificate.type.' . $this->value;
    }
}
