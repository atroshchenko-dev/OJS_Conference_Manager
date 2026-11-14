<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

/**
 * Organizer type (INS-01). Used for presets and reports, never for
 * hard-coded behaviour of one particular institution.
 */
enum InstitutionType: string
{
    case University = 'university';          // ЗВО
    case College = 'college';                // ЗФПО / фаховий коледж
    case Ippo = 'ippo';                      // інститут післядипломної педагогічної освіти
    case ResearchInstitute = 'research_institute';
    case Man = 'man';                        // Мала академія наук
    case Ngo = 'ngo';                        // громадська організація
    case Other = 'other';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.institution.type.' . $this->value;
    }
}
