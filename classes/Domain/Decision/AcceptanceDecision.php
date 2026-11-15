<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Decision;

use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;

/**
 * Editorial decisions 901–904 (DEC-01): accept the abstract as an oral,
 * poster, online or absentee (publication only) presentation. Each one
 * moves the submission to copyediting like "Accept and skip review".
 */
enum AcceptanceDecision: int
{
    case Oral = 901;
    case Poster = 902;
    case Online = 903;
    case Absentee = 904;

    public static function forType(PresentationType $type): self
    {
        return match ($type) {
            PresentationType::Oral => self::Oral,
            PresentationType::Poster => self::Poster,
            PresentationType::Online => self::Online,
            PresentationType::Absentee => self::Absentee,
        };
    }

    public function presentationType(): PresentationType
    {
        return match ($this) {
            self::Oral => PresentationType::Oral,
            self::Poster => PresentationType::Poster,
            self::Online => PresentationType::Online,
            self::Absentee => PresentationType::Absentee,
        };
    }

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.decision.accept.' . $this->presentationType()->value;
    }

    /** Key of the letter to the authors in OJS email templates. */
    public function emailTemplateKey(): string
    {
        return 'CONF_DECISION_' . strtoupper($this->presentationType()->value);
    }
}
