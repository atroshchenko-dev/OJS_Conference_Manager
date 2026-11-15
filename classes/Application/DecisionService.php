<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AcceptanceDecision;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * What follows an editorial decision on a conference submission (DEC-02),
 * a later change of the presentation type (DEC-05) and the conference
 * panel of the submission card (DEC-06).
 */
final class DecisionService
{
    /** OJS Decision::ACCEPT and Decision::SKIP_EXTERNAL_REVIEW. */
    public const CORE_ACCEPT = [2, 17];

    private const K = 'plugins.generic.conferenceManager.decision.error.';

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionRepository $editions,
        private readonly EditorialDirectory $editorial,
        private readonly InvoiceService $invoices,
        private readonly ?ProofService $proofs = null,
        private readonly ?TypeChangeNotifier $notifier = null,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
    ) {
    }

    public static function isAcceptance(int $code): bool
    {
        return AcceptanceDecision::tryFrom($code) !== null || in_array($code, self::CORE_ACCEPT, true);
    }

    /**
     * After an acceptance: 901–904 set the presentation type; every
     * acceptance puts the submission in the issue of the edition and
     * issues the presenter's invoice. Submissions outside conferences are
     * left alone.
     *
     * @return array{handled: bool, issueAssigned: bool, invoice: ?string}
     */
    public function onDecision(ContextInfo $context, int $submissionId, int $code, ?int $actorId, DateTimeImmutable $now): array
    {
        $none = ['handled' => false, 'issueAssigned' => false, 'invoice' => null];
        if (!self::isAcceptance($code)) {
            return $none;
        }
        $registration = $this->registrations->findBySubmission($submissionId);
        if ($registration === null || $registration->editionId === null) {
            return $none;
        }
        $edition = $this->editions->find($context->id, $registration->editionId);
        if ($edition === null) {
            return $none;
        }
        $accept = AcceptanceDecision::tryFrom($code);
        if ($accept !== null) {
            $type = $accept->presentationType();
            if ($registration->presentationType !== $type) {
                $this->registrations->save($registration->withPresentationType($type));
            }
            $this->editorial->setPresentationType($submissionId, $type->value);
        }
        $assigned = $edition->issueId !== null && $this->editorial->assignIssue($submissionId, $edition->issueId);
        $invoice = $this->invoices->onAccepted($context, $submissionId, $actorId, $now);
        $this->audit?->log($context->id, $actorId, 'submission', $submissionId, 'accepted', null, [
            'decision' => $code,
            'presentationType' => $accept?->presentationType()->value,
            'issueId' => $assigned ? $edition->issueId : null,
            'invoice' => $invoice['result'],
        ]);
        return ['handled' => true, 'issueAssigned' => $assigned, 'invoice' => $invoice['result']];
    }

    /**
     * DEC-05: the editor changes the presentation type after the decision,
     * without a new decision. The authors get a letter; an unpaid invoice
     * is issued anew when the tariff depends on the type.
     *
     * @return ValidationErrors|array{changed: bool, invoice: ?string}|null null when not a conference submission
     */
    public function changeType(ContextInfo $context, int $submissionId, string $type, ?int $actorId, DateTimeImmutable $now): ValidationErrors|array|null
    {
        $registration = $this->registrations->findBySubmission($submissionId);
        $summary = $this->editorial->summaries([$submissionId], $context->primaryLocale)[$submissionId] ?? null;
        if ($registration === null || $registration->editionId === null || $summary === null || $summary['contextId'] !== $context->id) {
            return null;
        }
        $errors = new ValidationErrors();
        $new = PresentationType::tryFrom($type);
        if ($new === null) {
            $errors->add('presentationType', self::K . 'type');
        }
        if (!in_array($summary['stageId'], [EditorialDirectory::STAGE_EDITING, EditorialDirectory::STAGE_PRODUCTION], true)) {
            $errors->add('presentationType', self::K . 'notAccepted');
        }
        if (!$errors->isEmpty() || $new === null) {
            return $errors;
        }
        $old = $registration->presentationType;
        if ($old === $new) {
            return ['changed' => false, 'invoice' => null];
        }
        $this->registrations->save($registration->withPresentationType($new));
        $this->editorial->setPresentationType($submissionId, $new->value);
        $invoice = $this->invoices->reissueForSubmission($context, $submissionId, $actorId, $now);
        $this->audit?->log($context->id, $actorId, 'submission', $submissionId, 'presentationType', ['presentationType' => $old?->value], [
            'presentationType' => $new->value,
            'invoice' => $invoice['result'],
        ]);
        if ($this->notifier !== null) {
            $locale = $context->primaryLocale;
            try {
                $this->notifier->typeChanged(
                    $context,
                    $submissionId,
                    $old === null ? '' : $this->t($old->labelKey(), $locale),
                    $this->t($new->labelKey(), $locale),
                    $actorId,
                );
            } catch (\Throwable $e) {
                error_log('conferenceManager: type change letter failed: ' . $e->getMessage());
            }
        }
        return ['changed' => true, 'invoice' => $invoice['result']];
    }

    /**
     * DEC-06: the conference panel of the submission card.
     *
     * @return array<string, mixed>|null null when not a conference submission
     */
    public function panel(ContextInfo $context, int $submissionId, string $locale): ?array
    {
        $registration = $this->registrations->findBySubmission($submissionId);
        if ($registration === null || $registration->editionId === null) {
            return null;
        }
        $edition = $this->editions->find($context->id, $registration->editionId);
        if ($edition === null) {
            return null;
        }
        $summary = $this->editorial->summaries([$submissionId], $locale)[$submissionId] ?? null;
        $invoice = $this->invoices->currentForSubmission($submissionId);
        $presentedInvoice = null;
        if ($invoice !== null) {
            $presentedInvoice = $this->invoices->present($context, $invoice, $locale);
            if ($this->proofs !== null) {
                $presentedInvoice = $this->proofs->withProofs($context, [$presentedInvoice], $locale)[0];
            }
        }
        $type = $registration->presentationType;
        $stage = $summary['stageId'] ?? 0;
        return [
            'submissionId' => $submissionId,
            'edition' => [
                'id' => $edition->id,
                'path' => $edition->path,
                'title' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale),
            ],
            'presentationType' => $type?->value,
            'presentationTypeLabel' => $type === null ? null : $this->t($type->labelKey(), $locale),
            'presentationTypes' => array_map(fn (PresentationType $p): array => ['value' => $p->value, 'label' => $this->t($p->labelKey(), $locale)], PresentationType::cases()),
            'canChangeType' => in_array($stage, [EditorialDirectory::STAGE_EDITING, EditorialDirectory::STAGE_PRODUCTION], true),
            'presenterMinor' => $registration->presenterMinor,
            'registered' => $registration->registeredAt !== null,
            'participants' => array_map(fn (array $p): array => $p + [
                'roleLabel' => $this->t('plugins.generic.conferenceManager.registration.participantRole.' . $p['role'], $locale),
                'confirmationLabel' => $this->t('plugins.generic.conferenceManager.registration.confirmationStatus.' . $p['confirmationStatus'], $locale),
            ], $this->registrations->participantsOfSubmission($submissionId)),
            'invoice' => $presentedInvoice === null ? null : array_intersect_key($presentedInvoice, array_flip([
                'id', 'number', 'status', 'statusLabel', 'total', 'paid', 'due', 'isOpen', 'issuedAt', 'proofs',
            ])),
        ];
    }

    private function t(string $key, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, [], $locale);
    }
}
