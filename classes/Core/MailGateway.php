<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Application\ConfirmationRequest;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\ProofNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\TypeChangeNotifier;
use APP\plugins\generic\conferenceManager\classes\Core\Mail\ConferenceTypeChanged;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Pages\ConferencePageHandler;
use APP\submission\Submission;
use Illuminate\Support\Facades\Mail;
use PKP\context\Context;
use PKP\log\SubmissionEmailLogEventType;
use PKP\mail\Mailable;
use PKP\user\User;

/**
 * Letters of the plugin through the OJS mailer, from the journal's
 * contact address, in the journal's primary language.
 */
final class MailGateway implements ParticipantNotifier, InvoiceNotifier, ProofNotifier, TypeChangeNotifier
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

    public function proofReviewed(ContextInfo $context, Invoice $invoice, array $presented, bool $accepted, ?string $note): void
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
        $due = (array) $presented['due'];
        $params = [
            'name' => htmlspecialchars($name !== '' ? $name : $email),
            'edition' => htmlspecialchars((string) $presented['editionName']),
            'number' => htmlspecialchars((string) $invoice->number),
            'status' => htmlspecialchars((string) $presented['statusLabel']),
            'due' => htmlspecialchars($due['decimal'] . ' ' . $due['currency']),
            'note' => nl2br(htmlspecialchars((string) $note)),
            'url' => htmlspecialchars($url),
            'journal' => htmlspecialchars((string) $journal->getLocalizedData('name', $locale)),
        ];
        $kind = $accepted ? 'accepted' : 'rejected';
        $mailable = new Mailable();
        $mailable
            ->from((string) $journal->getData('contactEmail'), (string) $journal->getData('contactName'))
            ->to($email, $name !== '' ? $name : null)
            ->subject(__('plugins.generic.conferenceManager.proof.mail.' . $kind . '.subject', ['edition' => (string) $presented['editionName'], 'number' => (string) $invoice->number], $locale))
            ->body(__('plugins.generic.conferenceManager.proof.mail.' . $kind . '.body', $params, $locale));
        Mail::send($mailable);
    }

    /**
     * DEC-05: the journal's "presentation type changed" letter to the
     * authors, logged in the submission's email log.
     */
    public function typeChanged(ContextInfo $context, int $submissionId, string $oldLabel, string $newLabel, ?int $editorId): void
    {
        $journal = Application::getContextDAO()->getById($context->id);
        $submission = Repo::submission()->get($submissionId);
        if (!$journal instanceof Context || !$submission instanceof Submission) {
            return;
        }
        $template = Repo::emailTemplate()->getByKey($context->id, ConferenceTypeChanged::getEmailTemplateKey());
        $authors = array_values(array_filter(array_map(
            static fn (int $id): ?User => Repo::user()->get($id),
            EditorialGateway::authorUserIds($submissionId),
        )));
        if ($template === null || $authors === []) {
            return;
        }
        $locale = (string) ($submission->getData('locale') ?: $context->primaryLocale);
        $editor = $editorId === null ? null : Repo::user()->get($editorId);
        $mailable = new ConferenceTypeChanged($journal, $submission, htmlspecialchars($oldLabel));
        if ($editor instanceof User) {
            $mailable->sender($editor);
        } else {
            $mailable->from((string) $journal->getData('contactEmail'), (string) $journal->getData('contactName'));
        }
        $mailable->recipients($authors, $locale);
        $mailable
            ->subject((string) ($template->getData('subject', $locale) ?: $template->getData('subject', $context->primaryLocale)))
            ->body((string) ($template->getData('body', $locale) ?: $template->getData('body', $context->primaryLocale)));
        Mail::send($mailable);
        if ($editor instanceof User) {
            Repo::emailLogEntry()->logMailable(SubmissionEmailLogEventType::EDITOR_NOTIFY_AUTHOR, $mailable, $submission, $editor);
        }
    }
}
