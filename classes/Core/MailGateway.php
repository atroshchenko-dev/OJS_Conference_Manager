<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Application\ConfirmationRequest;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantNotifier;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Pages\ConferencePageHandler;
use Illuminate\Support\Facades\Mail;
use PKP\context\Context;
use PKP\mail\Mailable;

/**
 * Letters of the plugin through the OJS mailer, from the journal's
 * contact address, in the journal's primary language.
 */
final class MailGateway implements ParticipantNotifier
{
    private const K = 'plugins.generic.conferenceManager.registration.mail.';

    public function requestConfirmation(ContextInfo $context, ConfirmationRequest $request): void
    {
        $journal = Application::getContextDAO()->getById($context->id);
        if (!$journal instanceof Context) {
            return;
        }
        $locale = $context->primaryLocale;
        $httpRequest = Application::get()->getRequest();
        $url = $httpRequest->getDispatcher()->url(
            $httpRequest,
            Application::ROUTE_PAGE,
            $journal->getPath(),
            ConferencePageHandler::PAGE,
            'confirm',
            [$request->token],
        );
        $edition = (new LocalizedText($request->editionTitle))->resolve($locale) ?? '';
        $submission = (new LocalizedText($request->submissionTitle))->resolve($locale) ?? '';
        $expires = $request->expiresAt->setTimezone(new \DateTimeZone($request->timezone))->format('d.m.Y');
        $params = [
            'name' => htmlspecialchars($request->name !== '' ? $request->name : $request->email),
            'edition' => htmlspecialchars($edition),
            'submission' => htmlspecialchars($submission),
            'submitter' => htmlspecialchars($request->submitterName),
            'role' => htmlspecialchars(__('plugins.generic.conferenceManager.registration.participantRole.' . $request->role, [], $locale)),
            'url' => htmlspecialchars($url),
            'expires' => $expires,
            'journal' => htmlspecialchars((string) $journal->getLocalizedData('name', $locale)),
        ];

        $mailable = new Mailable();
        $mailable
            ->from((string) $journal->getData('contactEmail'), (string) $journal->getData('contactName'))
            ->to($request->email, $request->name !== '' ? $request->name : null)
            ->subject(__(self::K . 'confirmSubject', ['edition' => $edition], $locale))
            ->body(__(self::K . 'confirmBody', $params, $locale));
        Mail::send($mailable);
    }
}
