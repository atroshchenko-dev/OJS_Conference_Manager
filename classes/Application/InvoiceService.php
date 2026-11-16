<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\MaterialType;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PricingMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Quote;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\TariffCalculator;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\TariffQuery;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\DocumentType;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionProfile;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccount;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\AmountInWords;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\GroupInvoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceItem;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PayerDetails;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRule;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRuleValidator;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\TextTemplate;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\Generic\GenericProfile;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionProfile;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use DateTimeImmutable;

/**
 * Payment modes of an edition and invoices (PAY-01…08, FEE-04…07).
 *
 * An invoice is issued for a listener right after registration and for a
 * presenter after the acceptance decision. No rule, the "free" mode or a
 * zero tariff mean nothing to pay: no invoice.
 */
final class InvoiceService
{
    private const K = 'plugins.generic.conferenceManager.payment.error.';
    private const I = 'plugins.generic.conferenceManager.invoice.';

    public const ISSUED = 'issued';
    public const FREE = 'free';
    public const NO_TARIFF = 'noTariff';
    public const PAGES_REQUIRED = 'pagesRequired';
    public const EXISTS = 'exists';
    public const NOT_FOUND = 'notFound';
    public const KEPT = 'kept';
    public const WAIVED = 'waived';
    private const SETTING_TAX_ID = 'payerTaxIdRequired';

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly FeeRepository $fees,
        private readonly EditionRepository $editions,
        private readonly ParticipantRepository $participants,
        private readonly RegistrationRepository $registrations,
        /** @var \Closure(ContextInfo): InstitutionProfile */
        private readonly \Closure $institution,
        private readonly ?InvoiceNotifier $notifier = null,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null translates a key with params into a locale */
        private readonly ?\Closure $translate = null,
        private readonly ?JurisdictionRegistry $jurisdictions = null,
        private readonly ?SettingsStore $settings = null,
        /** REG-11: the promo code a participation used lowers its fee */
        private readonly ?\APP\plugins\generic\conferenceManager\classes\Domain\Fee\PromoRepository $promos = null,
    ) {
    }

    /** JUR-05: whether payers who are individuals give their personal tax number (off by default). */
    public function personalTaxIdEnabled(ContextInfo $context): bool
    {
        return filter_var($this->settings?->get($context->id, self::SETTING_TAX_ID), FILTER_VALIDATE_BOOLEAN);
    }

    public function setPersonalTaxIdEnabled(ContextInfo $context, bool $on, ?int $actorId): void
    {
        $this->settings?->set($context->id, self::SETTING_TAX_ID, $on);
        $this->audit?->log($context->id, $actorId, 'invoice_settings', null, 'update', null, ['personalTaxId' => $on]);
    }

    /**
     * JUR-05: labels of the payer fields in the jurisdiction of the journal.
     *
     * @return array{legalId: string, taxId: string, taxIdEnabled: bool}
     */
    public function payerFields(ContextInfo $context): array
    {
        $rules = $this->jurisdiction(($this->institution)($context)) ?? new GenericProfile();
        return ['legalId' => $rules->getLegalIdLabelKey(), 'taxId' => $rules->getPersonalTaxIdLabelKey(), 'taxIdEnabled' => $this->personalTaxIdEnabled($context)];
    }

    /**
     * JUR-05: the payer of an open, unpaid invoice gives who pays: they
     * themselves or an organisation with its legal id. The details are
     * kept for the next invoices and written into this one; the payment
     * journal records the change. Null when the invoice is not theirs.
     *
     * @param array<string, mixed> $input kind, organization, legalId, taxId
     */
    public function savePayerDetails(ContextInfo $context, int $invoiceId, ?int $userId, string $email, array $input, DateTimeImmutable $now): ?ValidationErrors
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        if ($invoice === null || !$this->isPayer($invoice, $userId, $email)) {
            return null;
        }
        $errors = new ValidationErrors();
        if (!in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Overdue], true) || $invoice->paidAmount()->minor > 0) {
            $errors->add('kind', 'plugins.generic.conferenceManager.payer.error.locked');
            return $errors;
        }
        $rules = $this->jurisdiction(($this->institution)($context)) ?? new GenericProfile();
        [$details, $errors] = PayerDetails::fromInput($input, $rules, $this->personalTaxIdEnabled($context));
        if ($details === null) {
            return $errors;
        }
        $this->participants->setPayerDetails($invoice->payerParticipantId, $details->toArray());
        $payer = $this->participants->payer($invoice->payerParticipantId, $context->primaryLocale);
        if ($payer !== null) {
            $this->payments->changeStatus($invoice->id ?? 0, $invoice->status, ['payer_snapshot' => self::payerSnapshot($payer)], 'payer_changed', $userId, ['kind' => $details->kind], $now);
        }
        return $errors;
    }

    /**
     * The payer as copied into an invoice.
     *
     * @param array{name: string, email: string, affiliation: string, details?: array<string, string>} $payer
     * @return array<string, string>
     */
    public static function payerSnapshot(array $payer): array
    {
        $d = PayerDetails::fromArray($payer['details'] ?? []);
        $out = ['name' => $payer['name'], 'email' => $payer['email'], 'affiliation' => $payer['affiliation']];
        if ($d->isOrganization()) {
            $out = ['name' => $d->organization, 'email' => $payer['email'], 'affiliation' => '', 'kind' => $d->kind,
                'legalId' => $d->legalId, 'contactName' => $payer['name']];
        } elseif ($d->taxId !== '') {
            $out['taxId'] = $d->taxId;
        }
        return $out;
    }

    public function edition(ContextInfo $context, int $editionId): ?Edition
    {
        return $this->editions->find($context->id, $editionId);
    }

    /** @return list<PaymentRule>|null */
    public function rules(ContextInfo $context, int $editionId): ?array
    {
        return $this->edition($context, $editionId) === null ? null : $this->payments->rules($editionId);
    }

    /**
     * @param array<string, mixed> $input categoryId, mode, paymentAccountId, externalUrl, instructions{locale}
     * @return array{0: ?PaymentRule, 1: ValidationErrors}|null
     */
    public function saveRule(ContextInfo $context, int $editionId, ?int $id, array $input, ?int $actorId = null): ?array
    {
        if ($this->edition($context, $editionId) === null || ($id !== null && $this->payments->findRule($editionId, $id) === null)) {
            return null;
        }
        $errors = new ValidationErrors();
        $mode = PaymentMode::tryFrom((string) ($input['mode'] ?? ''));
        if ($mode === null) {
            $errors->add('mode', 'plugins.generic.conferenceManager.validation.required');
            return [null, $errors];
        }
        // PAY-09: card payment needs the journal's payment gateway first.
        $gateway = $this->settings?->get($context->id, OnlinePaymentService::SETTING);
        if ($mode === PaymentMode::Acquiring && (!is_array($gateway) || !is_string($gateway['gateway'] ?? null) || $gateway['gateway'] === '')) {
            $errors->add('mode', 'plugins.generic.conferenceManager.gateway.error.notSetUp');
            return [null, $errors];
        }
        $categoryId = ($input['categoryId'] ?? null) === null || $input['categoryId'] === '' || (int) $input['categoryId'] === 0 ? null : (int) $input['categoryId'];
        if ($categoryId !== null && $this->fees->findCategory($editionId, $categoryId) === null) {
            $errors->add('categoryId', 'plugins.generic.conferenceManager.listener.error.category');
            return [null, $errors];
        }
        $account = ($input['paymentAccountId'] ?? null) === null || $input['paymentAccountId'] === '' ? null : (int) $input['paymentAccountId'];
        $url = trim((string) ($input['externalUrl'] ?? ''));
        $rule = new PaymentRule(
            $id,
            $editionId,
            $categoryId,
            $mode,
            $mode->needsAccount() || $mode === PaymentMode::ExternalOrganizer ? $account : null,
            $url === '' || $mode === PaymentMode::Free ? null : mb_substr($url, 0, 2048),
            new LocalizedText(array_map(static fn (mixed $v): string => mb_substr(trim((string) $v), 0, 5000), (array) ($input['instructions'] ?? []))),
        );
        $errors = (new PaymentRuleValidator())->validate($rule, $this->payments->rules($editionId), ($this->institution)($context)->activePaymentAccounts());
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $saved = $this->payments->saveRule($rule);
        $this->audit?->log($context->id, $actorId, 'payment_rule', $saved->id, $id === null ? 'create' : 'update', null, $saved->toArray());
        return [$saved, $errors];
    }

    public function deleteRule(ContextInfo $context, int $editionId, int $id, ?int $actorId = null): bool
    {
        if ($this->edition($context, $editionId) === null || !$this->payments->deleteRule($editionId, $id)) {
            return false;
        }
        $this->audit?->log($context->id, $actorId, 'payment_rule', $id, 'delete');
        return true;
    }

    /**
     * Issues the invoice of a participation (FEE-04): a listener's fee, or
     * the fee for the submission of a presenter. Runs once: an existing
     * current invoice is returned as it is.
     *
     * @return array{result: string, invoice: ?Invoice}
     */
    public function issueFor(ContextInfo $context, int $participationId, ?int $pages, ?int $actorId, DateTimeImmutable $now): array
    {
        $p = $this->participants->findParticipation($context->id, $participationId);
        if ($p === null || $p['status'] !== ParticipationStatus::Active->value) {
            return ['result' => self::NOT_FOUND, 'invoice' => null];
        }
        $existing = $this->payments->currentFor($participationId, $p['role'] === ParticipantRole::Listener->value ? null : $p['submissionId']);
        if ($existing !== null) {
            return ['result' => self::EXISTS, 'invoice' => $existing];
        }
        [$result, $draft] = $this->draft($context, $p, $pages, $now);
        if ($draft === null) {
            return ['result' => $result, 'invoice' => null];
        }
        return ['result' => $result, 'invoice' => $this->create($context, $draft, $actorId, $now)];
    }

    /**
     * The invoice a participation would get now, not saved: the rule of the
     * payer's category, the tariff and the snapshots (FEE-04, FEE-08).
     *
     * @param array<string, mixed> $p participation (findParticipation)
     * @return array{0: string, 1: ?Invoice}
     */
    private function draft(ContextInfo $context, array $p, ?int $pages, DateTimeImmutable $now): array
    {
        $edition = $this->edition($context, $p['editionId']);
        $payer = $this->participants->payer($p['participantId'], $context->primaryLocale);
        if ($edition === null || $payer === null) {
            return [self::NOT_FOUND, null];
        }
        $rule = PaymentRule::pick($this->payments->rules($edition->id ?? 0), $payer['categoryId']);
        if ($rule === null || !$rule->mode->issuesInvoice() || $payer['categoryId'] === null) {
            return [self::FREE, null];
        }

        [$material, $quote] = $this->quoteFor($edition, $p, $payer['categoryId'], $pages, $now);
        if (!$quote->isOk()) {
            return [$quote->problem === Quote::PAGES_REQUIRED ? self::PAGES_REQUIRED : self::NO_TARIFF, null];
        }
        if ($quote->isFree()) {
            return [self::FREE, null];
        }

        $total = $quote->total;
        $tariff = $quote->tariff;
        if ($total === null || $tariff === null) {
            return [self::NO_TARIFF, null];
        }
        $perPage = $tariff->pricing === PricingMode::PerPage;
        $locale = $context->primaryLocale;
        // REG-10: an invited participant gets a waiver with the same item at zero.
        $invited = $this->participants->isInvited($p['participantId']);
        $editionName = (string) $edition->text('name')->resolve($locale, $locale);
        if ($invited) {
            $total = Money::zero($total->currency);
        }
        $description = $this->t(self::I . 'item.' . $material->value, ['edition' => $editionName], $locale);
        $quantity = $perPage ? (int) $quote->pages : 1;
        $unit = $invited ? $total : ($perPage ? $tariff->amount : $total);
        // REG-11: one line with the price after the discount and the code in its description.
        $promo = $invited ? null : $this->promos?->ofParticipation((int) $p['participationId']);
        if ($promo !== null) {
            $discounted = $promo->apply($total);
            if ($discounted->minor !== $total->minor) {
                $description .= $this->t(self::I . 'promoSuffix', ['code' => $promo->code, 'discount' => $promo->discountText(), 'full' => $total->decimal() . ' ' . $total->currency], $locale);
                $total = $discounted;
                $quantity = 1;
                $unit = $total;
            } else {
                $promo = null;
            }
        }
        $item = new InvoiceItem(
            $description,
            $quantity,
            $unit,
            $total,
            (int) $p['participationId'],
            $p['submissionId'],
            $tariff->id,
        );
        $invoice = new Invoice(
            null,
            $context->id,
            $edition->id ?? 0,
            $p['participantId'],
            $rule->mode,
            InvoiceStatus::Issued,
            $total,
            [$item],
            $rule->paymentAccountId,
            dueAt: $edition->date('paymentDeadline'),
            payer: self::payerSnapshot($payer),
            recipient: $this->recipient($context, $rule, $edition),
            method: $rule->mode->method(),
        );
        if ($promo !== null && $total->isZero()) {
            return [self::WAIVED, $invoice->with([
                'status' => InvoiceStatus::Waived,
                'method' => 'waiver',
                'paid' => $total,
                'waiverReason' => $this->t(self::I . 'promoReason', ['code' => $promo->code], $locale),
            ])];
        }
        if ($invited) {
            return [self::WAIVED, $invoice->with([
                'status' => InvoiceStatus::Waived,
                'method' => 'waiver',
                'paid' => $total,
                'waiverReason' => $this->t(self::I . 'invitedReason', [], $locale),
            ])];
        }
        return [self::ISSUED, $invoice];
    }

    /** Saves a drafted invoice, logs it and sends the letter to the payer. */
    private function create(ContextInfo $context, Invoice $draft, ?int $actorId, DateTimeImmutable $now): Invoice
    {
        $invoice = $this->payments->createInvoice($draft, $actorId, $now);
        $this->audit?->log($context->id, $actorId, 'invoice', $invoice->id, 'issued', $draft->supersedesId === null ? null : ['invoice' => $draft->supersedesId], [
            'number' => $invoice->number, 'total' => $invoice->total->minor, 'paid' => $invoice->paidAmount()->minor,
        ]);
        try {
            // A waiver asks for nothing: the invited person gets the invitation instead.
            if ($invoice->status === InvoiceStatus::Waived) {
                return $invoice;
            }
            $this->notifier?->invoiceIssued($context, $invoice, $this->present($context, $invoice, $context->primaryLocale));
        } catch (\Throwable $e) {
            // A failed letter must not undo the invoice: it is in the cabinet.
            error_log('conferenceManager: invoice letter failed: ' . $e->getMessage());
        }
        return $invoice;
    }

    /**
     * FEE-08: a new invoice instead of an open or paid one, after the
     * number of pages, the payer's category or the presentation type
     * changed. What was paid is carried over, so only the difference is due;
     * the old invoice becomes "superseded" and points to the new one.
     *
     * @return array{0: ?Invoice, 1: ValidationErrors}|null
     */
    public function reissue(ContextInfo $context, int $invoiceId, ?int $pages, ?int $actorId, DateTimeImmutable $now): ?array
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        if ($invoice === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if ($pages !== null && ($pages < 1 || $pages > 1000)) {
            $errors->add('pages', 'plugins.generic.conferenceManager.fee.error.pages');
            return [null, $errors];
        }
        $item = $invoice->items[0] ?? null;
        $p = $item?->participationId === null ? null : $this->participants->findParticipation($context->id, (int) $item->participationId);
        if ($invoice->isWaiver() || GroupInvoice::isGroup($invoice) || !in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Overdue, InvoiceStatus::Paid], true)
            || $p === null || $p['status'] !== ParticipationStatus::Active->value) {
            $errors->add('status', self::K . 'notReissuable');
            return [null, $errors];
        }
        if ($pages === null && $item->quantity > 1) {
            $pages = $item->quantity;
        }
        [$result, $draft] = $this->draft($context, $p, $pages, $now);
        if ($draft === null) {
            $errors->add('pages', self::K . 'reissue.' . $result);
            return [null, $errors];
        }
        $paid = $invoice->paidAmount();
        if ($draft->total->currency !== $invoice->total->currency && $paid->minor > 0) {
            $errors->add('status', self::K . 'reissue.currency');
            return [null, $errors];
        }
        if ($draft->total->minor === $invoice->total->minor && $draft->total->currency === $invoice->total->currency
            && $draft->items[0]->quantity === $item->quantity) {
            $errors->add('pages', self::K . 'reissue.unchanged');
            return [null, $errors];
        }
        if ($paid->minor >= $draft->total->minor && $paid->minor > 0) {
            $errors->add('status', self::K . 'reissue.overpaid');
            return [null, $errors];
        }
        $new = $this->create($context, $draft->with([
            'supersedesId' => $invoice->id,
            'paid' => $paid->minor > 0 ? $paid : null,
        ]), $actorId, $now);
        return [$new, $errors];
    }

    /**
     * FEE-04: after an acceptance decision the presenter gets the invoice.
     *
     * @return array{result: string, invoice: ?Invoice}
     */
    public function onAccepted(ContextInfo $context, int $submissionId, ?int $actorId, DateTimeImmutable $now): array
    {
        $participationId = $this->participants->presenterOf($submissionId);
        if ($participationId === null) {
            return ['result' => self::NOT_FOUND, 'invoice' => null];
        }
        return $this->issueFor($context, $participationId, null, $actorId, $now);
    }

    /** The presenter's current invoice for the submission (DEC-06). */
    /** The invoice that counts now for a participation (listener) or its submission. */
    public function currentFor(int $participationId, ?int $submissionId = null): ?Invoice
    {
        return $this->payments->currentFor($participationId, $submissionId);
    }

    public function currentForSubmission(int $submissionId): ?Invoice
    {
        $participationId = $this->participants->presenterOf($submissionId);
        return $participationId === null ? null : $this->payments->currentFor($participationId, $submissionId);
    }

    /**
     * @param array<string, mixed> $p participation (findParticipation)
     * @return array{0: MaterialType, 1: Quote}
     */
    private function quoteFor(Edition $edition, array $p, int $categoryId, ?int $pages, DateTimeImmutable $now): array
    {
        $presentation = null;
        if ($p['role'] === ParticipantRole::Listener->value) {
            $material = MaterialType::Listener;
        } else {
            $material = $pages === null ? MaterialType::Abstract : MaterialType::Paper;
            $presentation = $p['submissionId'] === null ? null : $this->registrations->findBySubmission((int) $p['submissionId'])?->presentationType;
        }
        return [$material, (new TariffCalculator())->quote(
            $this->fees->tariffs($edition->id ?? 0),
            new TariffQuery($categoryId, $material, $now, $pages, $presentation),
        )];
    }

    /**
     * DEC-05: the presentation type of an accepted submission changed. An
     * invoice whose amount no longer matches the tariff is replaced by a new
     * one (FEE-08): an unpaid one is cancelled, a paid or part-paid one is
     * superseded and what was paid is carried over, so the presenter pays
     * only the difference. When the payment already covers the new amount,
     * the invoice stays as it is (a refund is the manager's call).
     *
     * @return array{result: string, invoice: ?Invoice, previous: ?Invoice}
     */
    public function reissueForSubmission(ContextInfo $context, int $submissionId, ?int $actorId, DateTimeImmutable $now): array
    {
        $participationId = $this->participants->presenterOf($submissionId);
        if ($participationId === null) {
            return ['result' => self::NOT_FOUND, 'invoice' => null, 'previous' => null];
        }
        $current = $this->payments->currentFor($participationId, $submissionId);
        if ($current === null) {
            return $this->issueFor($context, $participationId, null, $actorId, $now) + ['previous' => null];
        }
        if ($current->isWaiver() || !in_array($current->status, [InvoiceStatus::Issued, InvoiceStatus::Overdue, InvoiceStatus::Paid], true)) {
            return ['result' => self::KEPT, 'invoice' => $current, 'previous' => null];
        }
        if ($current->paidAmount()->minor > 0) {
            $done = $this->reissue($context, (int) $current->id, null, $actorId, $now);
            $new = $done === null ? null : $done[0];
            return $new === null
                ? ['result' => self::KEPT, 'invoice' => $current, 'previous' => null]
                : ['result' => self::ISSUED, 'invoice' => $new, 'previous' => $current];
        }
        $p = $this->participants->findParticipation($context->id, $participationId);
        if ($p === null) {
            return ['result' => self::KEPT, 'invoice' => $current, 'previous' => null];
        }
        $pages = null;
        foreach ($current->items as $item) {
            if ($item->submissionId === $submissionId && $item->quantity > 1) {
                $pages = $item->quantity;
            }
        }
        [, $draft] = $this->draft($context, $p, $pages, $now);
        if ($draft !== null && $draft->total->minor === $current->total->minor && $draft->total->currency === $current->total->currency) {
            return ['result' => self::KEPT, 'invoice' => $current, 'previous' => null];
        }
        $this->payments->changeStatus((int) $current->id, InvoiceStatus::Cancelled, [], 'cancelled', $actorId, ['reason' => 'presentation_type'], $now);
        $this->audit?->log($context->id, $actorId, 'invoice', $current->id, 'cancelled', null, ['reason' => 'presentation_type']);
        return $this->issueFor($context, $participationId, $pages, $actorId, $now) + ['previous' => $current];
    }

    /**
     * FEE-11: a paid material is published only after payment. Returns the
     * presenter's invoice that still waits for money, or null when nothing
     * blocks: no invoice (free participation), paid or waived.
     */
    public function unpaidForPublication(int $submissionId): ?Invoice
    {
        $invoice = $this->currentForSubmission($submissionId);
        return $invoice !== null && $invoice->status->isOpen() && !$invoice->isWaiver() && $invoice->due()->minor > 0 ? $invoice : null;
    }

    /**
     * FEE-08: the chain of invoices of a participation, newest first, with
     * the superseded and cancelled ones, so the manager sees what replaced
     * what.
     *
     * @return list<array<string, mixed>>
     */
    public function history(ContextInfo $context, int $invoiceId, string $locale): array
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        $participationId = $invoice?->items[0]->participationId ?? null;
        if ($invoice === null || $participationId === null) {
            return [];
        }
        $all = array_filter(
            $this->payments->ofParticipants([$invoice->payerParticipantId]),
            static fn (Invoice $i): bool => ($i->items[0]->participationId ?? null) === $participationId,
        );
        return array_values(array_map(fn (Invoice $i): array => $this->present($context, $i, $locale), $all));
    }

    /**
     * The manager records a payment. A smaller amount is a part payment:
     * the invoice stays open with what is still due.
     */
    public function markPaid(ContextInfo $context, int $invoiceId, ?string $amount, ?string $note, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        if ($invoice === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if (!$invoice->status->isOpen()) {
            $errors->add('status', self::K . 'notOpen');
            return $errors;
        }
        $paid = $amount === null || trim($amount) === '' ? $invoice->due() : Money::parse($amount, $invoice->total->currency);
        if ($paid === null || $paid->minor <= 0) {
            $errors->add('amount', 'plugins.generic.conferenceManager.fee.error.amount');
            return $errors;
        }
        $sum = $invoice->paidAmount()->minor + $paid->minor;
        $full = $sum >= $invoice->total->minor;
        $this->payments->changeStatus(
            $invoiceId,
            $full ? InvoiceStatus::Paid : $invoice->status,
            ['paid_minor' => $sum] + ($full ? ['paid_at' => DateInput::toStorage($now)] : []),
            $full ? 'paid' : 'part_paid',
            $actorId,
            ['amount' => $paid->minor, 'note' => $note === null ? null : mb_substr($note, 0, 1000)],
            $now,
        );
        $this->audit?->log($context->id, $actorId, 'invoice', $invoiceId, $full ? 'paid' : 'part_paid', null, ['amount' => $paid->minor]);
        return $errors;
    }

    /**
     * FEE-07: exemption = a new invoice with zero total, method "waiver" and
     * the reason; the old one becomes "superseded". Not for paid invoices.
     *
     * @return array{0: ?Invoice, 1: ValidationErrors}|null
     */
    public function waive(ContextInfo $context, int $invoiceId, string $reason, ?int $actorId, DateTimeImmutable $now): ?array
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        if ($invoice === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') {
            $errors->add('reason', self::K . 'reason');
        }
        if (!$invoice->status->isOpen() || $invoice->paidAmount()->minor > 0) {
            $errors->add('status', self::K . 'notOpen');
        }
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $zero = Money::zero($invoice->total->currency);
        $items = array_map(static fn (InvoiceItem $i): InvoiceItem => new InvoiceItem($i->description, $i->quantity, $zero, $zero, $i->participationId, $i->submissionId, $i->tariffId), $invoice->items);
        $waiver = $this->payments->createInvoice($invoice->with([
            'id' => null,
            'status' => InvoiceStatus::Waived,
            'total' => $zero,
            'items' => $items,
            'paid' => $zero,
            'waiverReason' => $reason,
            'supersedesId' => $invoice->id,
            'method' => 'waiver',
            'number' => null,
            'numberYear' => null,
            'paymentReference' => null,
        ]), $actorId, $now);
        $this->audit?->log($context->id, $actorId, 'invoice', $waiver->id, 'waived', ['invoice' => $invoiceId], ['reason' => $reason]);
        return [$waiver, $errors];
    }

    public function cancel(ContextInfo $context, int $invoiceId, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        if ($invoice === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if (!$invoice->status->isOpen() || $invoice->paidAmount()->minor > 0) {
            $errors->add('status', self::K . 'notOpen');
            return $errors;
        }
        $this->payments->changeStatus($invoiceId, InvoiceStatus::Cancelled, [], 'cancelled', $actorId, ['reason' => 'manager'], $now);
        $this->audit?->log($context->id, $actorId, 'invoice', $invoiceId, 'cancelled');
        return $errors;
    }

    /**
     * REG-09: one invoice for several participants of the edition, paid by
     * an organisation. Each member gets an item by their own category and
     * tariff. Open unpaid invoices of the members are superseded; a member
     * who paid something, has no fee or is in another group is refused, so
     * the manager settles them first.
     *
     * @param list<int> $participationIds
     * @param array<string, mixed> $input organization, legalId
     * @return array{0: ?Invoice, 1: ValidationErrors, 2: array<int, string>} the invoice, errors, refused participations with the reason
     */
    public function issueGroup(ContextInfo $context, int $editionId, array $participationIds, int $contactParticipationId, array $input, ?int $actorId, DateTimeImmutable $now): array
    {
        $errors = new ValidationErrors();
        $ids = array_values(array_unique(array_filter(array_map('intval', $participationIds), static fn (int $i): bool => $i > 0)));
        if (count($ids) < GroupInvoice::MIN || count($ids) > GroupInvoice::MAX) {
            $errors->add('participationIds', self::K . 'group.count');
            return [null, $errors, []];
        }
        if (!in_array($contactParticipationId, $ids, true)) {
            $errors->add('contactParticipationId', self::K . 'group.contact');
        }
        $rules = $this->jurisdiction(($this->institution)($context)) ?? new GenericProfile();
        [$details, $detailErrors] = PayerDetails::fromInput(['kind' => PayerDetails::ORGANIZATION] + $input, $rules, false);
        $errors->merge($detailErrors);
        if (!$errors->isEmpty() || $details === null) {
            return [null, $errors, []];
        }

        $refused = [];
        $drafts = [];
        $replaced = [];
        $contact = null;
        foreach ($ids as $id) {
            $p = $this->participants->findParticipation($context->id, $id);
            if ($p === null || $p['editionId'] !== $editionId || $p['status'] !== ParticipationStatus::Active->value) {
                $refused[$id] = 'notActive';
                continue;
            }
            $current = $this->payments->currentFor($id, $p['role'] === ParticipantRole::Listener->value ? null : $p['submissionId']);
            if ($current !== null && (!$current->status->isOpen() || $current->paidAmount()->minor > 0 || GroupInvoice::isGroup($current))) {
                $refused[$id] = $current->status === InvoiceStatus::Waived ? 'waived' : 'paid';
                continue;
            }
            [$result, $draft] = $this->draft($context, $p, null, $now);
            if ($draft === null || $result !== self::ISSUED) {
                $refused[$id] = $result === self::WAIVED ? 'invited' : $result;
                continue;
            }
            $payer = $this->participants->payer($p['participantId'], $context->primaryLocale);
            $drafts[] = ['invoice' => $draft, 'name' => (string) ($payer['name'] ?? '')];
            if ($current !== null) {
                $replaced[] = (int) $current->id;
            }
            if ($id === $contactParticipationId) {
                $contact = ['participantId' => $p['participantId'], 'payer' => $payer];
            }
        }
        if ($refused !== []) {
            $errors->add('participationIds', self::K . 'group.refused');
            return [null, $errors, $refused];
        }
        if ($contact === null || $contact['payer'] === null) {
            $errors->add('contactParticipationId', self::K . 'group.contact');
            return [null, $errors, []];
        }
        $snapshot = ['name' => $details->organization, 'email' => $contact['payer']['email'], 'affiliation' => '', 'kind' => PayerDetails::ORGANIZATION,
            'legalId' => $details->legalId, 'contactName' => $contact['payer']['name']];
        [$group, $problem] = GroupInvoice::combine($drafts, $contact['participantId'], $snapshot);
        if ($group === null) {
            $errors->add('participationIds', self::K . 'group.' . ($problem ?? 'count'));
            return [null, $errors, []];
        }
        foreach ($replaced as $old) {
            $this->payments->changeStatus($old, InvoiceStatus::Superseded, [], 'superseded', $actorId, ['reason' => 'group'], $now);
        }
        $invoice = $this->create($context, $group, $actorId, $now);
        foreach ($replaced as $old) {
            $this->audit?->log($context->id, $actorId, 'invoice', $old, 'superseded', null, ['groupInvoice' => $invoice->id]);
        }
        $this->audit?->log($context->id, $actorId, 'invoice', $invoice->id, 'group_issued', null, ['participations' => $ids, 'replaced' => $replaced]);
        return [$invoice, $errors, []];
    }

    /**
     * FEE-15: the payer asked for the money back, or the manager records
     * that they did. The invoice keeps what was paid until the refund.
     */
    public function requestRefund(ContextInfo $context, int $invoiceId, string $note, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        if ($invoice === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $paidSomething = $invoice->status === InvoiceStatus::Paid || ($invoice->status->isOpen() && $invoice->paidAmount()->minor > 0);
        if (!$paidSomething) {
            $errors->add('status', self::K . 'refund.notPaid');
            return $errors;
        }
        $note = mb_substr(trim($note), 0, 1000);
        $this->payments->changeStatus($invoiceId, InvoiceStatus::RefundRequested, ['refund_note' => $note === '' ? null : $note], 'refund_requested', $actorId, ['note' => $note], $now);
        $this->audit?->log($context->id, $actorId, 'invoice', $invoiceId, 'refund_requested', ['status' => $invoice->status->value], ['note' => $note]);
        return $errors;
    }

    /**
     * FEE-15: the money went back. Without an amount all that was paid is
     * refunded; a smaller amount is a part refund (for example one member
     * of a group cancelled). The note says why and how; both go to the
     * export for accounting.
     */
    public function refund(ContextInfo $context, int $invoiceId, ?string $amount, string $note, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        if ($invoice === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $paid = $invoice->paidAmount();
        $left = $paid->minor - $invoice->refundedAmount()->minor;
        $allowed = in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::RefundRequested], true) || ($invoice->status->isOpen() && $paid->minor > 0);
        if (!$allowed || $left <= 0) {
            $errors->add('status', self::K . 'refund.notPaid');
            return $errors;
        }
        $sum = $amount === null || trim($amount) === '' ? new Money($left, $paid->currency) : Money::parse($amount, $paid->currency);
        if ($sum === null || $sum->minor <= 0 || $sum->minor > $left) {
            $errors->add('amount', self::K . 'refund.amount');
            return $errors;
        }
        $note = mb_substr(trim($note), 0, 1000);
        if ($note === '') {
            $errors->add('note', self::K . 'refund.note');
            return $errors;
        }
        $total = $invoice->refundedAmount()->minor + $sum->minor;
        $full = $total >= $paid->minor;
        $this->payments->changeStatus(
            $invoiceId,
            $full ? InvoiceStatus::Refunded : ($invoice->status === InvoiceStatus::RefundRequested ? InvoiceStatus::Paid : $invoice->status),
            ['refunded_minor' => $total, 'refunded_at' => DateInput::toStorage($now), 'refund_note' => $note],
            $full ? 'refunded' : 'part_refunded',
            $actorId,
            ['amount' => $sum->minor, 'note' => $note],
            $now,
        );
        $this->audit?->log($context->id, $actorId, 'invoice', $invoiceId, $full ? 'refunded' : 'part_refunded', ['status' => $invoice->status->value], ['amount' => $sum->minor, 'note' => $note]);
        return $errors;
    }

    public function find(ContextInfo $context, int $invoiceId): ?Invoice
    {
        return $this->payments->findInvoice($context->id, $invoiceId);
    }

    /**
     * Whether the person may see the invoice: its payer (by account or email).
     */
    /**
     * The person's own invoices (cabinet), found by user id or email.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(ContextInfo $context, int $userId, string $email, string $locale): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $p): int => $p['participantId'], $this->participants->ofPerson($context->id, $userId, $email))));
        return $this->ofParticipants($context, $ids, $locale);
    }

    public function isPayer(Invoice $invoice, ?int $userId, string $email): bool
    {
        $payer = $this->participants->payer($invoice->payerParticipantId, 'en');
        if ($payer === null) {
            return false;
        }
        return ($userId !== null && $payer['userId'] === $userId)
            || ($email !== '' && mb_strtolower(trim($email)) === mb_strtolower($payer['email']));
    }

    /**
     * @return array{items: list<array<string, mixed>>, itemsMax: int}|null
     */
    public function ofEdition(ContextInfo $context, int $editionId, ?InvoiceStatus $status, string $search, int $count, int $offset, string $locale): ?array
    {
        if ($this->edition($context, $editionId) === null) {
            return null;
        }
        $page = $this->payments->ofEdition($context->id, $editionId, $status, $search, $count, $offset);
        return [
            'items' => array_map(fn (Invoice $i): array => $this->present($context, $i, $locale), $page['items']),
            'itemsMax' => $page['itemsMax'],
        ];
    }

    /**
     * Invoices of the person in the context (REG-06 cabinet), newest first.
     *
     * @param list<int> $participantIds
     * @return list<array<string, mixed>>
     */
    public function ofParticipants(ContextInfo $context, array $participantIds, string $locale): array
    {
        return array_map(fn (Invoice $i): array => $this->present($context, $i, $locale), $this->payments->ofParticipants($participantIds));
    }

    /** @return list<array<string, mixed>> */
    public function events(ContextInfo $context, int $invoiceId): array
    {
        $invoice = $this->payments->findInvoice($context->id, $invoiceId);
        if ($invoice === null) {
            return [];
        }
        $currency = $invoice->total->currency;
        return array_map(static function (array $e) use ($currency): array {
            $amount = is_array($e['payload'] ?? null) && is_int($e['payload']['amount'] ?? null) ? $e['payload']['amount'] : null;
            return $e + ['amount' => $amount === null ? null : (new Money($amount, $currency))->toArray()];
        }, $this->payments->events($invoiceId));
    }

    /**
     * Everything an invoice shows: in the cabinet, the list, the letter and
     * the PDF. Built from the snapshots, so it does not change later.
     *
     * @return array<string, mixed>
     */
    public function present(ContextInfo $context, Invoice $invoice, string $locale): array
    {
        $r = $invoice->recipient;
        $editionName = (string) (new LocalizedText((array) ($r['editionName'] ?? [])))->resolve($locale, $context->primaryLocale);
        $purpose = (new TextTemplate((string) ($r['purposeTemplate'] ?? '')))->render([
            'invoiceNumber' => (string) $invoice->number,
            'paymentReference' => (string) $invoice->paymentReference,
            'payerName' => (string) ($invoice->payer['name'] ?? ''),
            'editionTitle' => $editionName,
            'editionPath' => (string) ($r['editionPath'] ?? ''),
            'year' => (string) $invoice->numberYear,
            'amount' => $invoice->total->decimal(),
            'currency' => $invoice->total->currency,
        ]);
        $instructions = (string) (new LocalizedText((array) ($r['instructions'] ?? [])))->resolve($locale, $context->primaryLocale);
        if ($instructions === '') {
            $instructions = $this->t(self::I . 'instructions.' . $invoice->mode->value, [], $locale);
        }
        if (!empty($r['cardWarning']) && $invoice->mode->hasDocument()) {
            $instructions = trim($instructions . "\n\n" . $this->t(self::I . 'instructions.noCard', [], $locale));
        }
        return [
            'id' => $invoice->id,
            'editionId' => $invoice->editionId,
            'editionName' => $editionName,
            'payerParticipantId' => $invoice->payerParticipantId,
            'number' => $invoice->number,
            'paymentReference' => $invoice->paymentReference,
            'status' => $invoice->status->value,
            'statusLabel' => $this->t($invoice->status->labelKey(), [], $locale),
            'mode' => $invoice->mode->value,
            'modeLabel' => $this->t($invoice->mode->labelKey(), [], $locale),
            'method' => $invoice->method,
            'hasDocument' => $invoice->mode->hasDocument() && !$invoice->isWaiver(),
            'total' => $invoice->total->toArray(),
            'paid' => $invoice->paidAmount()->toArray(),
            'due' => $invoice->due()->toArray(),
            'totalInWords' => AmountInWords::format($invoice->total, $locale),
            'waiverReason' => $invoice->waiverReason,
            'supersedesId' => $invoice->supersedesId,
            'issuedAt' => DateInput::toIsoUtc($invoice->issuedAt),
            'dueAt' => DateInput::toIsoUtc($invoice->dueAt),
            'paidAt' => DateInput::toIsoUtc($invoice->paidAt),
            'timezone' => (string) ($r['timezone'] ?? 'UTC'),
            'payer' => $invoice->payer,
            'recipient' => [
                'institution' => (string) (new LocalizedText((array) ($r['institution'] ?? [])))->resolve($locale, $context->primaryLocale),
                'institutionLegalId' => (string) ($r['institutionLegalId'] ?? ''),
                'address' => (string) (new LocalizedText((array) ($r['address'] ?? [])))->resolve($locale, $context->primaryLocale),
                'account' => (array) ($r['account'] ?? []),
                'accountFields' => (array) ($r['accountFields'] ?? []),
                'signerName' => (string) (new LocalizedText((array) ($r['signer']['fullName'] ?? [])))->resolve($locale, $context->primaryLocale),
                'signerPosition' => (string) (new LocalizedText((array) ($r['signer']['position'] ?? [])))->resolve($locale, $context->primaryLocale),
            ],
            'purpose' => $purpose,
            'instructions' => $instructions,
            'externalUrl' => $r['externalUrl'] ?? null,
            'items' => array_map(static fn (InvoiceItem $i): array => $i->toArray(), $invoice->items),
            'isOpen' => $invoice->status->isOpen(),
            'isGroup' => GroupInvoice::isGroup($invoice),
            'refunded' => $invoice->refundedAmount()->toArray(),
            'refundedAt' => DateInput::toIsoUtc($invoice->refundedAt),
            'refundNote' => $invoice->refundNote,
            'canRequestRefund' => $invoice->status === InvoiceStatus::Paid || ($invoice->status->isOpen() && $invoice->paidAmount()->minor > 0),
            'canRefund' => in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::RefundRequested], true) || ($invoice->status->isOpen() && $invoice->paidAmount()->minor > 0),
        ];
    }

    /**
     * The recipient as it is at issue time (FEE-06).
     *
     * @return array<string, mixed>
     */
    private function recipient(ContextInfo $context, PaymentRule $rule, Edition $edition): array
    {
        $profile = ($this->institution)($context);
        $account = null;
        foreach ($profile->activePaymentAccounts() as $a) {
            if ($a->id === $rule->paymentAccountId) {
                $account = $a;
            }
        }
        $jurisdiction = $this->jurisdiction($profile);
        $features = $jurisdiction?->getFeatures() ?? [];
        $locale = $context->primaryLocale;
        return [
            'institution' => $profile->text('name')->toArray(),
            'institutionLegalId' => (string) $profile->legalId,
            'address' => $profile->text('address')->toArray(),
            'account' => $account === null ? [] : array_intersect_key($account->toArray(), array_flip(['kind', 'recipientName', 'iban', 'bankName', 'legalId', 'extra'])),
            'accountFields' => $account === null ? [] : $this->accountFields($account, $jurisdiction, $locale),
            'purposeTemplate' => $account !== null && $account->purposeTemplate !== ''
                ? $account->purposeTemplate
                : $this->t(self::I . 'purpose.default', [], $locale),
            'instructions' => $rule->instructions->toArray(),
            'externalUrl' => $rule->externalUrl,
            'editionName' => $edition->text('name')->toArray(),
            'editionPath' => $edition->path,
            'timezone' => $edition->timezone,
            'cardWarning' => in_array(JurisdictionProfile::FEATURE_CARD_WARNING, $features, true),
            'signer' => $this->signer($profile),
        ];
    }

    /**
     * Labelled extra fields of the account (PAY-03), in the primary language.
     *
     * @return list<array{label: string, value: string}>
     */
    private function accountFields(PaymentAccount $account, ?JurisdictionProfile $jurisdiction, string $locale): array
    {
        $labels = [];
        foreach ($jurisdiction?->getPaymentAccountExtraFields($account->kind) ?? [] as $field) {
            $labels[$field->name] = $this->t($field->labelKey, [], $locale);
        }
        $out = [];
        foreach ($account->extra as $name => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $out[] = ['label' => (string) ($labels[$name] ?? $name), 'value' => (string) $value];
            }
        }
        return $out;
    }

    /** @return array{fullName: array<string, string>, position: array<string, string>}|null */
    private function signer(InstitutionProfile $profile): ?array
    {
        foreach ($profile->signers as $s) {
            if ($s->signs(DocumentType::Invoice)) {
                return ['fullName' => $s->fullName->toArray(), 'position' => $s->position->toArray()];
            }
        }
        return null;
    }

    private function jurisdiction(InstitutionProfile $profile): ?JurisdictionProfile
    {
        return $this->jurisdictions !== null && $this->jurisdictions->has($profile->jurisdiction)
            ? $this->jurisdictions->get($profile->jurisdiction)
            : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function t(string $key, array $params, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, $params, $locale);
    }
}
