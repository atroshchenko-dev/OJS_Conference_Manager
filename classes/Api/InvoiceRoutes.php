<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

use APP\plugins\generic\conferenceManager\classes\Application\InvoiceService;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeCategory;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccount;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRule;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\security\Role;

/**
 * Payment rules, invoices and invoice templates (PAY-01…08, FEE-04…07).
 *
 * Journal manager (configuration):
 * GET    editions/{id}/payment-rules                 rules and choices
 * POST   editions/{id}/payment-rules                 add {categoryId, mode, paymentAccountId, externalUrl, instructions}
 * PUT    editions/{id}/payment-rules/{rid}           change
 * DELETE editions/{id}/payment-rules/{rid}           delete
 * GET    invoice-templates                           both templates, built-in and own
 * PUT    invoice-templates/{kind}                    {html}; empty = back to the built-in one
 *
 * Registration manager or journal manager (invoices):
 * GET    editions/{id}/invoices?status&searchPhrase&count&offset
 * POST   participations/{pid}/invoice                issue now {pages}
 * GET    invoices/{iid}                              invoice with its journal of events
 * GET    invoices/{iid}/pdf                          the document
 * POST   invoices/{iid}/paid                         {amount, note}; less than due = part payment
 * POST   invoices/{iid}/waive                        {reason}
 * POST   invoices/{iid}/cancel
 * POST   invoices/{iid}/reissue                     {pages}; new invoice, paid amount carried over (FEE-08)
 * GET    editions/{id}/payment-events?count&offset  the payment journal (FEE-13)
 * GET    proofs?status=pending&editionId&count&offset  receipts to review (FEE-10)
 * GET    proofs/{prid}/file                          the receipt file
 * POST   proofs/{prid}/accept                        {amount}; empty = what is due
 * POST   proofs/{prid}/reject                        {note}
 */
final class InvoiceRoutes implements RouteModule
{
    public function __construct(private readonly ConferenceApiController $api)
    {
    }

    public function roles(): array
    {
        return [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_ASSISTANT];
    }

    public function routes(): void
    {
        $rules = 'editions/{editionId}/payment-rules';
        Route::get($rules, $this->getPaymentRules(...))->name('conference.paymentRules.get')->whereNumber('editionId');
        Route::post($rules, $this->addPaymentRule(...))->name('conference.paymentRules.add')->whereNumber('editionId');
        Route::put($rules . '/{ruleId}', $this->editPaymentRule(...))->name('conference.paymentRules.edit')->whereNumber(['editionId', 'ruleId']);
        Route::delete($rules . '/{ruleId}', $this->deletePaymentRule(...))->name('conference.paymentRules.delete')->whereNumber(['editionId', 'ruleId']);
        Route::get('invoice-templates', $this->getInvoiceTemplates(...))->name('conference.invoiceTemplates.get');
        Route::put('invoice-templates/{kind}', $this->editInvoiceTemplate(...))->name('conference.invoiceTemplates.edit')->whereIn('kind', ['bank', 'treasury']);

        Route::get('editions/{editionId}/invoices', $this->getEditionInvoices(...))->name('conference.invoices.list')->whereNumber('editionId');
        Route::post('participations/{participationId}/invoice', $this->issueInvoice(...))->name('conference.invoices.issue')->whereNumber('participationId');
        Route::get('invoices/{invoiceId}', $this->getInvoice(...))->name('conference.invoices.get')->whereNumber('invoiceId');
        Route::get('invoices/{invoiceId}/pdf', $this->getInvoicePdf(...))->name('conference.invoices.pdf')->whereNumber('invoiceId');
        Route::post('invoices/{invoiceId}/paid', $this->markInvoicePaid(...))->name('conference.invoices.paid')->whereNumber('invoiceId');
        Route::post('invoices/{invoiceId}/waive', $this->waiveInvoice(...))->name('conference.invoices.waive')->whereNumber('invoiceId');
        Route::post('invoices/{invoiceId}/reissue', $this->reissueInvoice(...))->name('conference.invoices.reissue')->whereNumber('invoiceId');
        Route::post('invoices/{invoiceId}/cancel', $this->cancelInvoice(...))->name('conference.invoices.cancel')->whereNumber('invoiceId');
        Route::get('editions/{editionId}/payment-events', $this->getPaymentJournal(...))->name('conference.payments.journal')->whereNumber('editionId');
        Route::get('proofs', $this->getProofs(...))->name('conference.proofs.list');
        Route::get('proofs/{proofId}/file', $this->getProofFile(...))->name('conference.proofs.file')->whereNumber('proofId');
        Route::post('proofs/{proofId}/accept', $this->acceptProof(...))->name('conference.proofs.accept')->whereNumber('proofId');
        Route::post('proofs/{proofId}/reject', $this->rejectProof(...))->name('conference.proofs.reject')->whereNumber('proofId');
    }

    private function isManager(): bool
    {
        return $this->api->userHasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function getPaymentRules(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $context = $this->api->contextInfo();
        $id = (int) $r->route('editionId');
        $rules = ServiceFactory::invoices()->rules($context, $id);
        if ($rules === null) {
            return ConferenceApiController::notFound();
        }
        $locale = \PKP\facades\Locale::getLocale();
        return response()->json([
            'rules' => array_map(static fn (PaymentRule $x): array => $x->toArray(), $rules),
            'options' => [
                'modes' => array_map(static fn (PaymentMode $m): array => [
                    'value' => $m->value,
                    'label' => __($m->labelKey()),
                    'needsAccount' => $m->needsAccount(),
                    'needsUrl' => $m->needsUrl(),
                ], PaymentMode::cases()),
                'accounts' => array_map(static fn (PaymentAccount $a): array => [
                    'id' => $a->id,
                    'kind' => $a->kind->value,
                    'label' => trim(($a->label !== '' ? $a->label : $a->recipientName) . ' · ' . $a->iban),
                ], ServiceFactory::institutionProfiles()->getOrDraft($context)->activePaymentAccounts()),
                'categories' => array_map(static fn (FeeCategory $c): array => [
                    'id' => $c->id,
                    'label' => (string) $c->name->resolve($locale, $context->primaryLocale),
                ], ServiceFactory::fees()->categories($context, $id) ?? []),
                'locales' => $context->formLocales,
            ],
        ], Response::HTTP_OK);
    }

    public function addPaymentRule(Request $r): JsonResponse
    {
        return $this->ruleResult($r, null);
    }

    public function editPaymentRule(Request $r): JsonResponse
    {
        return $this->ruleResult($r, (int) $r->route('ruleId'));
    }

    private function ruleResult(Request $r, ?int $ruleId): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $out = ServiceFactory::invoices()->saveRule($this->api->contextInfo(), (int) $r->route('editionId'), $ruleId, (array) $r->input(), $this->api->actorId());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$rule, $errors] = $out;
        return $rule === null
            ? ConferenceApiController::errorResponse($errors)
            : response()->json($rule->toArray(), Response::HTTP_OK);
    }

    public function deletePaymentRule(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $id = (int) $r->route('ruleId');
        return ServiceFactory::invoices()->deleteRule($this->api->contextInfo(), (int) $r->route('editionId'), $id, $this->api->actorId())
            ? response()->json(['id' => $id], Response::HTTP_OK)
            : ConferenceApiController::notFound();
    }

    public function getInvoiceTemplates(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        return response()->json(['templates' => array_values(ServiceFactory::invoiceDocuments()->templates($this->api->contextInfo()))], Response::HTTP_OK);
    }

    public function editInvoiceTemplate(Request $r): JsonResponse
    {
        if (!$this->isManager()) {
            return ConferenceApiController::forbidden();
        }
        $kind = (string) $r->route('kind');
        $errors = ServiceFactory::invoiceDocuments()->saveTemplate($this->api->contextInfo(), $kind, (string) $r->input('html', ''), $this->api->actorId());
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        return $errors->isEmpty()
            ? response()->json(ServiceFactory::invoiceDocuments()->templates($this->api->contextInfo())[$kind], Response::HTTP_OK)
            : ConferenceApiController::errorResponse($errors);
    }

    public function getEditionInvoices(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $page = ServiceFactory::invoices()->ofEdition(
            $this->api->contextInfo(),
            (int) $r->route('editionId'),
            InvoiceStatus::tryFrom((string) $r->query('status', '')),
            (string) $r->query('searchPhrase', ''),
            (int) $r->query('count', '50'),
            (int) $r->query('offset', '0'),
            \PKP\facades\Locale::getLocale(),
        );
        if ($page === null) {
            return ConferenceApiController::notFound();
        }
        $page['statuses'] = array_map(static fn (InvoiceStatus $s): array => ['value' => $s->value, 'label' => __($s->labelKey())], InvoiceStatus::cases());
        return response()->json($page, Response::HTTP_OK);
    }

    public function issueInvoice(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $pages = $r->input('pages');
        $pages = $pages === null || $pages === '' ? null : max(1, (int) $pages);
        $context = $this->api->contextInfo();
        $service = ServiceFactory::invoices();
        $out = $service->issueFor($context, (int) $r->route('participationId'), $pages, $this->api->actorId(), self::now());
        $invoice = $out['invoice'] === null ? null : $service->present($context, $out['invoice'], \PKP\facades\Locale::getLocale());
        return match ($out['result']) {
            InvoiceService::NOT_FOUND => ConferenceApiController::notFound(),
            InvoiceService::ISSUED, InvoiceService::EXISTS => response()->json(['result' => $out['result'], 'invoice' => $invoice], Response::HTTP_OK),
            default => response()->json([
                'result' => $out['result'],
                'invoice' => null,
                'message' => __('plugins.generic.conferenceManager.invoice.result.' . $out['result']),
            ], Response::HTTP_OK),
        };
    }

    public function getInvoice(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $context = $this->api->contextInfo();
        $service = ServiceFactory::invoices();
        $invoice = $service->find($context, (int) $r->route('invoiceId'));
        if ($invoice === null) {
            return ConferenceApiController::notFound();
        }
        return response()->json($service->present($context, $invoice, \PKP\facades\Locale::getLocale()) + [
            'events' => $service->events($context, (int) $invoice->id),
            'history' => $service->history($context, (int) $invoice->id, \PKP\facades\Locale::getLocale()),
        ], Response::HTTP_OK);
    }

    public function getInvoicePdf(Request $r): Response|JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        return self::pdfResponse((int) $r->route('invoiceId'), $this->api, null);
    }

    /**
     * The PDF for a manager, or for the payer when $payer = [userId, email].
     *
     * @param array{0: int, 1: string}|null $payer
     */
    public static function pdfResponse(int $invoiceId, ConferenceApiController $api, ?array $payer): Response|JsonResponse
    {
        $context = $api->contextInfo();
        $invoice = ServiceFactory::invoices()->find($context, $invoiceId);
        if ($invoice === null) {
            return ConferenceApiController::notFound();
        }
        if ($payer !== null && !ServiceFactory::invoices()->isPayer($invoice, $payer[0], $payer[1])) {
            return ConferenceApiController::forbidden();
        }
        $pdf = ServiceFactory::invoiceDocuments()->pdf($context, $invoice);
        if ($pdf === null) {
            return ConferenceApiController::notFound();
        }
        return new Response($pdf['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $pdf['name'] . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function markInvoicePaid(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $amount = $r->input('amount');
        $note = $r->input('note');
        $errors = ServiceFactory::invoices()->markPaid(
            $this->api->contextInfo(),
            (int) $r->route('invoiceId'),
            $amount === null ? null : (string) $amount,
            is_string($note) ? $note : null,
            $this->api->actorId(),
            self::now(),
        );
        return $this->statusResult($r, $errors);
    }

    public function waiveInvoice(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $context = $this->api->contextInfo();
        $service = ServiceFactory::invoices();
        $out = $service->waive($context, (int) $r->route('invoiceId'), (string) $r->input('reason', ''), $this->api->actorId(), self::now());
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$waiver, $errors] = $out;
        return $waiver === null
            ? ConferenceApiController::errorResponse($errors, $errors->has('status') ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST)
            : response()->json($service->present($context, $waiver, \PKP\facades\Locale::getLocale()), Response::HTTP_OK);
    }

    public function reissueInvoice(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $context = $this->api->contextInfo();
        $service = ServiceFactory::invoices();
        $pages = $r->input('pages');
        $out = $service->reissue(
            $context,
            (int) $r->route('invoiceId'),
            $pages === null || $pages === '' ? null : (int) $pages,
            $this->api->actorId(),
            self::now(),
        );
        if ($out === null) {
            return ConferenceApiController::notFound();
        }
        [$invoice, $errors] = $out;
        return $invoice === null
            ? ConferenceApiController::errorResponse($errors, $errors->has('status') ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST)
            : response()->json($service->present($context, $invoice, \PKP\facades\Locale::getLocale()), Response::HTTP_OK);
    }

    public function cancelInvoice(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $errors = ServiceFactory::invoices()->cancel($this->api->contextInfo(), (int) $r->route('invoiceId'), $this->api->actorId(), self::now());
        return $this->statusResult($r, $errors);
    }

    public function getPaymentJournal(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $page = ServiceFactory::proofs()->journal($this->api->contextInfo(), (int) $r->route('editionId'), (int) $r->query('count', '50'), (int) $r->query('offset', '0'), \PKP\facades\Locale::getLocale());
        return $page === null ? ConferenceApiController::notFound() : response()->json($page, Response::HTTP_OK);
    }

    public function getProofs(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $status = $r->query('status', 'pending');
        $status = $status === 'all' ? null : ProofStatus::tryFrom((string) $status);
        if ($status === null && $r->query('status', 'pending') !== 'all') {
            $errors = new ValidationErrors();
            $errors->add('status', 'plugins.generic.conferenceManager.proof.error.statusFilter');
            return ConferenceApiController::errorResponse($errors, Response::HTTP_BAD_REQUEST);
        }
        $edition = $r->query('editionId');
        $page = ServiceFactory::proofs()->queue(
            $this->api->contextInfo(),
            $edition === null || $edition === '' ? null : (int) $edition,
            $status,
            (int) $r->query('count', '30'),
            (int) $r->query('offset', '0'),
            \PKP\facades\Locale::getLocale(),
        );
        return response()->json($page, Response::HTTP_OK);
    }

    public function getProofFile(Request $r): Response|JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        return self::proofFileResponse($this->api, (int) $r->route('proofId'), null);
    }

    /**
     * The receipt for a manager, or for the payer when $payer = [userId, email].
     *
     * @param array{0: ?int, 1: string}|null $payer
     */
    public static function proofFileResponse(ConferenceApiController $api, int $proofId, ?array $payer): Response|JsonResponse
    {
        $file = ServiceFactory::proofs()->file($api->contextInfo(), $proofId, $payer);
        if ($file === null) {
            return ConferenceApiController::notFound();
        }
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['name']);
        return new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function acceptProof(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $amount = $r->input('amount');
        $errors = ServiceFactory::proofs()->accept($this->api->contextInfo(), (int) $r->route('proofId'), $amount === null ? null : (string) $amount, $this->api->actorId(), self::now());
        return $this->proofResult((int) $r->route('proofId'), $errors);
    }

    public function rejectProof(Request $r): JsonResponse
    {
        if (!$this->api->canManageRegistrations()) {
            return ConferenceApiController::forbidden();
        }
        $note = $r->input('note');
        $errors = ServiceFactory::proofs()->reject($this->api->contextInfo(), (int) $r->route('proofId'), is_string($note) ? $note : '', $this->api->actorId(), self::now());
        return $this->proofResult((int) $r->route('proofId'), $errors);
    }

    private function proofResult(int $proofId, ?ValidationErrors $errors): JsonResponse
    {
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        if (!$errors->isEmpty()) {
            return ConferenceApiController::errorResponse($errors, $errors->has('status') ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST);
        }
        $proof = ServiceFactory::proofs()->get($this->api->contextInfo(), $proofId, \PKP\facades\Locale::getLocale());
        return $proof === null ? ConferenceApiController::notFound() : response()->json($proof, Response::HTTP_OK);
    }

    private function statusResult(Request $r, ?ValidationErrors $errors): JsonResponse
    {
        if ($errors === null) {
            return ConferenceApiController::notFound();
        }
        if (!$errors->isEmpty()) {
            return ConferenceApiController::errorResponse($errors, $errors->has('status') ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST);
        }
        $context = $this->api->contextInfo();
        $service = ServiceFactory::invoices();
        $invoice = $service->find($context, (int) $r->route('invoiceId'));
        return $invoice === null
            ? ConferenceApiController::notFound()
            : response()->json($service->present($context, $invoice, \PKP\facades\Locale::getLocale()), Response::HTTP_OK);
    }
}
