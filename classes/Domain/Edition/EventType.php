<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

enum EventType: string
{
    case Conference = 'conference';
    case Seminar = 'seminar';
    case Webinar = 'webinar';
    case School = 'school';
    case RoundTable = 'round_table';
    case Contest = 'contest';
    case Other = 'other';

    /** Submission mode a new edition of this type starts with (EVT-01). */
    public function defaultSubmissionMode(): SubmissionMode
    {
        return match ($this) {
            self::Conference, self::Seminar, self::Other => SubmissionMode::Abstracts,
            self::Contest => SubmissionMode::AbstractsAndPapers,
            self::Webinar, self::School, self::RoundTable => SubmissionMode::None,
        };
    }

    /** Publication mode a new edition of this type starts with (EVT-01, EVT-03). */
    public function defaultPublicationMode(): PublicationMode
    {
        return $this === self::Conference ? PublicationMode::OjsIssue : PublicationMode::None;
    }

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.edition.eventType.' . $this->value;
    }
}
