<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

/**
 * Documents a signer can be assigned to (INS-02).
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case CertificateParticipation = 'certificate_participation';
    case CertificateSpeaker = 'certificate_speaker';
    case CertificateListener = 'certificate_listener';
    case CertificateOrganizer = 'certificate_organizer';
    case CertificateReviewer = 'certificate_reviewer';
    case CertificateProfessionalDevelopment = 'certificate_pk';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.documentType.' . $this->value;
    }
}
