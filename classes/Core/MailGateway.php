<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Application\ConfirmationRequest;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantMailer;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\ProofNotifier;
use APP\plugins\generic\conferenceManager\classes\Application\TypeChangeNotifier;
use APP\plugins\generic\conferenceManager\classes\Core\Mail\ConferenceParticipantMailable;
use APP\plugins\generic\conferenceManager\classes\Core\Mail\ConferenceTypeChanged;
use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
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
final class MailGateway implements ParticipantNotifier, InvoiceNotifier, ProofNotifier, TypeChangeNotifier, ParticipantMailer
{
    /** Variables whose values keep line breaks. */
    private const MULTILINE = ['paymentInstructions', 'reviewNote'];

    public function requestConfirmation(ContextInfo $context, ConfirmationRequest $request): void
    {
        $journal = $this->journal($context);
        if ($journal === null) {
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
        $this->sendTemplate($context, ParticipantMailTexts::CONFIRM, $request->email, $request->name !== '' ? $request->name : null, [
            'recipientName' => $request->name !== '' ? $request->name : $request->email,
            'conferenceName' => (string) (new LocalizedText($request->editionTitle))->resolve($locale),
            'submissionTitle' => (string) (new LocalizedText($request->submissionTitle))->resolve($locale),
            'submitterName' => $request->submitterName,
            'participantRole' => __('plugins.generic.conferenceManager.registration.participantRole.' . $request->role, [], $locale),
            'confirmUrl' => $url,
            'expiresAt' => $request->expiresAt->setTimezone(new \DateTimeZone($request->timezone))->format('d.m.Y'),
        ], $locale);
    }

    public function invoiceIssued(ContextInfo $context, Invoice $invoice, array $presented): void
    {
        $email = (string) ($invoice->payer['email'] ?? '');
        if ($email === '') {
            return;
        }
        $name = (string) ($invoice->payer['name'] ?? '');
        $total = (array) $presented['total'];
        $this->sendTemplate($context, ParticipantMailTexts::INVOICE, $email, $name !== '' ? $name : null, [
            'recipientName' => $name !== '' ? $name : $email,
            'conferenceName' => (string) $presented['editionName'],
            'invoiceNumber' => (string) $invoice->number,
            'paymentReference' => (string) $invoice->paymentReference,
            'amount' => $total['decimal'] . ' ' . $total['currency'],
            'paymentPurpose' => (string) $presented['purpose'],
            'paymentInstructions' => (string) $presented['instructions'],
            'dueDate' => self::localDate($invoice->dueAt, (string) $presented['timezone']),
        ], $context->primaryLocale);
    }

    public function proofReviewed(ContextInfo $context, Invoice $invoice, array $presented, bool $accepted, ?string $note): void
    {
        $email = (string) ($invoice->payer['email'] ?? '');
        if ($email === '') {
            return;
        }
        $name = (string) ($invoice->payer['name'] ?? '');
        $due = (array) $presented['due'];
        $this->sendTemplate($context, $accepted ? ParticipantMailTexts::PROOF_ACCEPTED : ParticipantMailTexts::PROOF_REJECTED, $email, $name !== '' ? $name : null, [
            'recipientName' => $name !== '' ? $name : $email,
            'conferenceName' => (string) $presented['editionName'],
            'invoiceNumber' => (string) $invoice->number,
            'invoiceStatus' => (string) $presented['statusLabel'],
            'amountDue' => $due['decimal'] . ' ' . $due['currency'],
            'reviewNote' => (string) $note,
        ], $context->primaryLocale);
    }

    public function sendTemplate(ContextInfo $context, string $templateKey, string $email, ?string $name, array $data, string $locale): void
    {
        $journal = $this->journal($context);
        $class = ConferenceParticipantMailable::forKey($templateKey);
        if ($journal === null || $class === null) {
            return;
        }
        $template = Repo::emailTemplate()->getByKey($context->id, $templateKey);
        if ($template === null) {
            throw new \RuntimeException('No email template ' . $templateKey);
        }
        $mailable = new $class($journal, $this->escape($data + [
            'cabinetUrl' => $this->cabinetUrl($context),
            'contextName' => (string) $journal->getLocalizedData('name', $locale),
        ]));
        $mailable
            ->from((string) $journal->getData('contactEmail'), (string) $journal->getData('contactName'))
            ->to($email, $name)
            ->subject((string) ($template->getData('subject', $locale) ?: $template->getData('subject', $context->primaryLocale)))
            ->body((string) ($template->getData('body', $locale) ?: $template->getData('body', $context->primaryLocale)));
        Mail::send($mailable);
    }

    public function sendText(ContextInfo $context, string $subject, string $text, string $email, ?string $name, array $data): void
    {
        $journal = $this->journal($context);
        if ($journal === null) {
            return;
        }
        $mailable = new Mailable([$journal]);
        $mailable->addData($this->escape($data));
        $mailable
            ->from((string) $journal->getData('contactEmail'), (string) $journal->getData('contactName'))
            ->to($email, $name)
            ->subject(str_replace(["\r", "\n"], ' ', $subject))
            ->body('<p>' . nl2br(htmlspecialchars($text), false) . '</p>');
        Mail::send($mailable);
    }

    public function cabinetUrl(ContextInfo $context): string
    {
        $journal = $this->journal($context);
        $request = Application::get()->getRequest();
        return $journal === null ? '' : (string) $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $journal->getPath(), ConferencePageHandler::PAGE, 'my');
    }

    public function editionUrl(ContextInfo $context, string $path): string
    {
        $journal = $this->journal($context);
        $request = Application::get()->getRequest();
        return $journal === null ? '' : (string) $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $journal->getPath(), ConferencePageHandler::PAGE, $path);
    }

    private function journal(ContextInfo $context): ?Context
    {
        $journal = Application::getContextDAO()->getById($context->id);
        return $journal instanceof Context ? $journal : null;
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private function escape(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = in_array($k, self::MULTILINE, true) ? nl2br(htmlspecialchars($v), false) : htmlspecialchars($v);
        }
        return $out;
    }

    private static function localDate(?\DateTimeImmutable $d, string $timezone): string
    {
        return $d === null ? '' : $d->setTimezone(new \DateTimeZone($timezone !== '' ? $timezone : 'UTC'))->format('d.m.Y');
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
