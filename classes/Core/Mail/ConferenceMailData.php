<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Pages\ConferencePageHandler;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;

/**
 * Conference variables of the decision letters: {$conferenceName},
 * {$presentationType} and {$cabinetUrl}.
 */
final class ConferenceMailData
{
    public const CONFERENCE = 'conferenceName';
    public const TYPE = 'presentationType';
    public const CABINET = 'cabinetUrl';
    public const PAPER_DEADLINE = 'paperDeadline';

    /** @return array<string, string> */
    public static function of(Context $context, Submission $submission, ?PresentationType $type = null): array
    {
        $publication = $submission->getCurrentPublication();
        $locale = (string) ($submission->getData('locale') ?: $context->getPrimaryLocale());
        $name = '';
        $editionId = (int) $publication?->getData('conferenceEditionId');
        if ($editionId > 0) {
            $edition = (new DbEditionRepository(DB::connection()))->find((int) $context->getId(), $editionId);
            $name = $edition === null ? '' : (string) $edition->text('name')->resolve($locale, $context->getPrimaryLocale());
        }
        $type ??= PresentationType::tryFrom((string) $publication?->getData('presentationType'));
        $request = Application::get()->getRequest();
        $url = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), ConferencePageHandler::PAGE, 'my');
        return [
            self::CONFERENCE => htmlspecialchars($name),
            self::TYPE => $type === null ? '' : htmlspecialchars(__($type->labelKey(), [], $locale)),
            self::CABINET => (string) $url,
        ];
    }

    /** DEC-07: the paper deadline of the edition in its time zone, or a note that the organizers will give it. */
    public static function paperDeadline(Context $context, Submission $submission): string
    {
        $editionId = (int) $submission->getCurrentPublication()?->getData('conferenceEditionId');
        $edition = $editionId > 0 ? (new DbEditionRepository(DB::connection()))->find((int) $context->getId(), $editionId) : null;
        $date = $edition?->date('paperDeadline');
        if ($edition === null || $date === null) {
            return __('plugins.generic.conferenceManager.mailable.paperDeadline.none', [], (string) ($submission->getData('locale') ?: $context->getPrimaryLocale()));
        }
        return $date->setTimezone(new \DateTimeZone($edition->timezone))->format('d.m.Y H:i');
    }

    /** @return array<string, string> */
    public static function descriptions(): array
    {
        $k = 'plugins.generic.conferenceManager.mailable.variable.';
        return [
            self::CONFERENCE => __($k . 'conferenceName'),
            self::TYPE => __($k . 'presentationType'),
            self::CABINET => __($k . 'cabinetUrl'),
        ];
    }
}
