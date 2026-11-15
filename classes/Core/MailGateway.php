<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Application\ConfirmationRequest;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantNotifier;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Pages\ConferencePageHandler;
use Illuminate\Support\Facades\Mail;
use PKP\context\Context;
use PKP\mail\Mailable;

/**
 * Letters of the plugin through the OJS mailer, from the journal's
 * contact address, in the journal's primary language.
 */
final class MailGateway implements ParticipantNotifier, InvoiceNotifier
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

    public function invoiceIssued(ContextInfo $context, Invoice $invoice, array $presented): void
    {
        $email = (string) ($invoice->payer['email'] ?? '');
        $journal = Application::getContextDAO()->getById($context->id);
        if ($email === '' || !$journal instanceof Context) {
            return;
        }
        $locale = $context->primaryLocale;
        $httpRequest = Application::get()->getRequest();
        $url = $httpRequest->getDispatcher()->url($httpRequest, Application::ROUTE_PAGE, $journal->getPath(), ConferencePageHandler::PAGE, 'my');
        $name = (string) ($invoice->payer['name'] ?? '');
        $total = (array) $presented['total'];
        $params = [
            'name' => htmlspecialchars($name !== '' ? $name : $email),
            'edition' => htmlspecialchars((string) $presented['editionName']),
            'number' => htmlspecialchars((string) $invoice->number),
            'reference' => htmlspecialchars((string) $invoice->paymentReference),
            'amount' => htmlspecialchars($total['decimal'] . ' ' . $total['currency']),
            'purpose' => htmlspecialchars((string) $presented['purpose']),
            'instructions' => nl2br(htmlspecialchars((string) $presented['instructions'])),
            'url' => htmlspecialchars($url),
            'journal' => htmlspecialchars((string) $journal->getLocalizedData('name', $locale)),
        ];
        $mailable = new Mailable();
        $mailable
            ->from((string) $journal->getData('contactEmail'), (string) $journal->getData('contactName'))
            ->to($email, $name !== '' ? $name : null)
            ->subject(__('plugins.generic.conferenceManager.invoice.mail.subject', ['edition' => (string) $presented['editionName'], 'number' => (string) $invoice->number], $locale))
            ->body(__('plugins.generic.conferenceManager.invoice.mail.body', $params, $locale));
        Mail::send($mailable);
    }
}
