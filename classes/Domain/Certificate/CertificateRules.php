<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Certificate;

use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;

/**
 * Who gets which certificate (CRT-05, ATT-03):
 *
 *   presenter          speaker certificate when present;
 *                      participation certificate when absentee and the paper is published;
 *   co-author          participation certificate when present, or absentee with the paper published;
 *   listener           listener certificate when present;
 *   chair, organizer   organizer certificate, no attendance needed;
 *   reviewer           reviewer certificate, no attendance needed.
 *
 * Accepted submissions only; cancelled participations get nothing.
 */
final class CertificateRules
{
    public const REASON_CANCELLED = 'cancelled';
    public const REASON_NOT_ATTENDED = 'notAttended';
    public const REASON_NOT_ACCEPTED = 'notAccepted';
    public const REASON_NOT_PUBLISHED = 'notPublished';

    /**
     * @return array{0: ?CertificateType, 1: ?string} the type, or null and the reason
     */
    public static function decide(
        ParticipantRole $role,
        bool $active,
        bool $attended,
        ParticipationForm $form,
        ?PresentationType $presentation,
        /** null for participations without a submission */
        ?bool $accepted,
        bool $published,
        /** the edition gives a professional development document (CRT-03): hours set, jurisdiction allows it */
        bool $professionalDevelopment = false,
    ): array {
        if (!$active) {
            return [null, self::REASON_CANCELLED];
        }
        switch ($role) {
            case ParticipantRole::Organizer:
            case ParticipantRole::Chair:
                return [CertificateType::Organizer, null];
            case ParticipantRole::Reviewer:
                return [CertificateType::Reviewer, null];
            case ParticipantRole::Listener:
                return $attended ? [$professionalDevelopment ? CertificateType::ProfessionalDevelopment : CertificateType::Listener, null] : [null, self::REASON_NOT_ATTENDED];
            case ParticipantRole::Presenter:
            case ParticipantRole::Coauthor:
                if ($accepted === false) {
                    return [null, self::REASON_NOT_ACCEPTED];
                }
                $absentee = $presentation === PresentationType::Absentee || $form === ParticipationForm::Absentee;
                if ($attended) {
                    return [$role === ParticipantRole::Presenter ? CertificateType::Speaker : CertificateType::Participation, null];
                }
                if ($absentee) {
                    return $published ? [CertificateType::Participation, null] : [null, self::REASON_NOT_PUBLISHED];
                }
        }
        return [null, self::REASON_NOT_ATTENDED];
    }
}
