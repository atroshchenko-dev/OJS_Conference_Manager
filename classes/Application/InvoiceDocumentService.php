<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceDocument;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;

/**
 * Invoice documents (FEE-06, PAY-07): two built-in templates, "bank" and
 * "treasury", that a context may replace without code. The PDF is made
 * on request from the invoice snapshots, so it is the same every time.
 */
final class InvoiceDocumentService
{
    private const L = 'plugins.generic.conferenceManager.invoice.document.';

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceTemplateStore $store,
        private readonly ?PdfRenderer $pdf,
        /** directory with the built-in templates "{kind}.{language}.html" */
        private readonly string $builtInDir,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    public function builtIn(string $kind, string $locale): string
    {
        if (!in_array($kind, InvoiceDocument::KINDS, true)) {
            return '';
        }
        $lang = strtolower(substr($locale, 0, 2));
        foreach ([$lang, 'en'] as $l) {
            $file = rtrim($this->builtInDir, '/') . '/' . $kind . '.' . $l . '.html';
            if (preg_match('/^[a-z]{2}$/', $l) && is_file($file)) {
                return (string) file_get_contents($file);
            }
        }
        return '';
    }

    /**
     * @return array<string, array{kind: string, custom: ?string, builtIn: string, variables: list<string>}>
     */
    public function templates(ContextInfo $context): array
    {
        $out = [];
        foreach (InvoiceDocument::KINDS as $kind) {
            $out[$kind] = [
                'kind' => $kind,
                'custom' => $this->store->get($context->id, $kind),
                'builtIn' => $this->builtIn($kind, $context->primaryLocale),
                'variables' => InvoiceDocument::VARIABLES,
            ];
        }
        return $out;
    }

    /**
     * An empty template returns to the built-in one.
     */
    public function saveTemplate(ContextInfo $context, string $kind, string $html, ?int $actorId = null): ?ValidationErrors
    {
        if (!in_array($kind, InvoiceDocument::KINDS, true)) {
            return null;
        }
        $html = trim($html);
        $errors = $html === '' ? new ValidationErrors() : InvoiceDocument::validate($html);
        if ($errors->isEmpty()) {
            $this->store->set($context->id, $kind, $html === '' ? null : $html);
            $this->audit?->log($context->id, $actorId, 'invoice_template', null, $html === '' ? 'reset' : 'update', null, ['kind' => $kind, 'sha256' => hash('sha256', $html)]);
        }
        return $errors;
    }

    /**
     * The HTML of the invoice, or null when the mode has no document.
     */
    public function html(ContextInfo $context, Invoice $invoice, ?string $locale = null): ?string
    {
        $kind = InvoiceDocument::kindFor($invoice->mode);
        if ($kind === null || $invoice->isWaiver()) {
            return null;
        }
        $locale ??= $context->primaryLocale;
        $template = $this->store->get($context->id, $kind) ?? $this->builtIn($kind, $locale);
        return InvoiceDocument::render($template, $this->invoices->present($context, $invoice, $locale), [
            'no' => $this->t('no', $locale),
            'description' => $this->t('description', $locale),
            'quantity' => $this->t('quantity', $locale),
            'price' => $this->t('price', $locale),
            'amount' => $this->t('amount', $locale),
            'total' => $this->t('total', $locale),
            'legalId' => $this->raw($this->invoices->payerFields($context)['legalId'], $locale),
            'taxId' => $this->raw($this->invoices->payerFields($context)['taxId'], $locale),
        ], str_starts_with($locale, 'en') ? '.' : ',');
    }

    /**
     * @return array{name: string, content: string}|null
     */
    public function pdf(ContextInfo $context, Invoice $invoice, ?string $locale = null): ?array
    {
        $html = $this->html($context, $invoice, $locale);
        if ($html === null || $this->pdf === null) {
            return null;
        }
        $name = 'invoice-' . preg_replace('/[^0-9A-Za-z-]/', '', (string) $invoice->number) . '.pdf';
        return ['name' => $name, 'content' => $this->pdf->render($html, (string) $invoice->number)];
    }

    private function t(string $key, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)(self::L . $key, [], $locale);
    }

    private function raw(string $key, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, [], $locale);
    }
}
