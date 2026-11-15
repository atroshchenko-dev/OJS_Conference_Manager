<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Partner;

/** INS-04: what an organisation does for the event. */
enum PartnerRole: string
{
    case CoOrganizer = 'co_organizer';
    case Partner = 'partner';
    case Sponsor = 'sponsor';
    case InfoPartner = 'info_partner';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.partner.role.' . $this->value;
    }
}
