<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\AccountingColumn;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Export\TableWriter;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Export of invoices for accounting (FEE-12): for a period, one edition or
 * all of them, CSV or XLSX, with the columns and their order that the
 * journal chose. Rows are read and written in portions.
 */
final class AccountingExportService
{
    public const SETTING = 'accountingExportColumns';
    private const K = 'plugins.generic.conferenceManager.export.';

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly FeeRepository $fees,
        private readonly EditionRepository $editions,
        private readonly InvoiceService $invoices,
        private readonly SettingsStore $settings,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
    ) {
    }

    /** @return list<AccountingColumn> */
    public function selected(ContextInfo $context): array
    {
        $saved = $this->settings->get($context->id, self::SETTING);
        $list = is_array($saved) ? AccountingColumn::parseList($saved) : [];
        return $list === [] ? AccountingColumn::defaults() : $list;
    }

    /** @return array{available: list<array{value: string, label: string}>, selected: list<string>} */
    public function columns(ContextInfo $context, string $locale): array
    {
        return [
            'available' => array_map(fn (AccountingColumn $c): array => ['value' => $c->value, 'label' => $this->t($c->labelKey(), [], $locale)], AccountingColumn::cases()),
            'selected' => array_map(static fn (AccountingColumn $c): string => $c->value, $this->selected($context)),
        ];
    }

    /** @param array<mixed> $values */
    public function saveColumns(ContextInfo $context, array $values, ?int $actorId): ValidationErrors
    {
        $errors = new ValidationErrors();
        $list = AccountingColumn::parseList($values);
        if ($list === []) {
            $errors->add('columns', self::K . 'error.columns');
            return $errors;
        }
        $before = array_map(static fn (AccountingColumn $c): string => $c->value, $this->selected($context));
        $after = array_map(static fn (AccountingColumn $c): string => $c->value, $list);
        $this->settings->set($context->id, self::SETTING, $after);
        $this->audit?->log($context->id, $actorId, 'export_columns', null, 'update', ['columns' => $before], ['columns' => $after]);
        return $errors;
    }

    /**
     * Writes the export. Dates are days (YYYY-MM-DD), both inclusive, in
     * UTC; an empty one means no limit.
     *
     * @param array<string, mixed> $input editionId, from, to, dateField (issued|paid), withReplaced
     * @return array{0: ?array{path: string, rows: int, filename: string, contentType: string}, 1: ValidationErrors}
     */
    public function export(ContextInfo $context, array $input, TableWriter $writer, string $locale, ?int $actorId): array
    {
        $errors = new ValidationErrors();
        $editionId = isset($input['editionId']) && $input['editionId'] !== '' ? (int) $input['editionId'] : null;
        if ($editionId !== null && $this->editions->find($context->id, $editionId) === null) {
            $errors->add('editionId', self::K . 'error.edition');
        }
        $from = self::day($input['from'] ?? null);
        $to = self::day($input['to'] ?? null);
        if ($from === false) {
            $errors->add('from', self::K . 'error.date');
        }
        if ($to === false) {
            $errors->add('to', self::K . 'error.date');
        }
        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable && $from > $to) {
            $errors->add('to', self::K . 'error.period');
        }
        $dateField = ($input['dateField'] ?? 'issued') === 'paid' ? 'paid' : 'issued';
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $columns = $this->selected($context);
        $numeric = array_map(static fn (AccountingColumn $c): bool => $c->isAmount(), $columns);
        $writer->open(array_map(fn (AccountingColumn $c): string => $this->t($c->labelKey(), [], $locale), $columns));
        $categories = [];
        $rows = 0;
        $this->payments->forExport(
            $context->id,
            $editionId,
            $from instanceof DateTimeImmutable ? $from : null,
            $to instanceof DateTimeImmutable ? $to->modify('+1 day') : null,
            $dateField,
            !empty($input['withReplaced']),
            function (array $portion) use ($context, $columns, $numeric, $writer, $locale, &$categories, &$rows): void {
                foreach ($portion as $entry) {
                    $invoice = $entry['invoice'];
                    if (!array_key_exists($invoice->editionId, $categories)) {
                        $categories[$invoice->editionId] = [];
                        foreach ($this->fees->categories($invoice->editionId) as $c) {
                            $categories[$invoice->editionId][(int) $c->id] = (string) $c->name->resolve($locale, $context->primaryLocale);
                        }
                    }
                    $category = $entry['categoryId'] === null ? '' : ($categories[$invoice->editionId][$entry['categoryId']] ?? '');
                    $writer->row($this->row($context, $invoice, $category, $entry['role'], $columns, $locale), $numeric);
                    $rows++;
                }
            },
        );
        $path = $writer->close();
        $this->audit?->log($context->id, $actorId, 'export', null, 'accounting', null, [
            'format' => $writer->extension(), 'editionId' => $editionId, 'from' => $input['from'] ?? null, 'to' => $input['to'] ?? null,
            'dateField' => $dateField, 'rows' => $rows,
        ]);
        return [[
            'path' => $path,
            'rows' => $rows,
            'filename' => 'accounting-' . ($editionId ?? 'all') . '-' . gmdate('Ymd') . '.' . $writer->extension(),
            'contentType' => $writer->contentType(),
        ], $errors];
    }

    /**
     * @param list<AccountingColumn> $columns
     * @return list<string>
     */
    private function row(ContextInfo $context, Invoice $invoice, string $category, ?string $role, array $columns, string $locale): array
    {
        $p = $this->invoices->present($context, $invoice, $locale);
        $tz = new DateTimeZone((string) $p['timezone']);
        $date = static fn (?DateTimeImmutable $d): string => $d === null ? '' : $d->setTimezone($tz)->format('d.m.Y');
        $account = (array) $p['recipient']['account'];
        $out = [];
        foreach ($columns as $c) {
            $out[] = match ($c) {
                AccountingColumn::Number => (string) $invoice->number,
                AccountingColumn::IssuedAt => $date($invoice->issuedAt),
                AccountingColumn::DueAt => $date($invoice->dueAt),
                AccountingColumn::PaidAt => $date($invoice->paidAt),
                AccountingColumn::Status => (string) $p['statusLabel'],
                AccountingColumn::PayerName => (string) ($invoice->payer['name'] ?? ''),
                AccountingColumn::PayerEmail => (string) ($invoice->payer['email'] ?? ''),
                AccountingColumn::PayerAffiliation => is_array($invoice->payer['affiliation'] ?? null)
                    ? (string) (new LocalizedText($invoice->payer['affiliation']))->resolve($locale, $context->primaryLocale)
                    : (string) ($invoice->payer['affiliation'] ?? ''),
                AccountingColumn::PayerCode => (string) (($invoice->payer['legalId'] ?? '') !== '' ? $invoice->payer['legalId'] : ($invoice->payer['taxId'] ?? '')),
                AccountingColumn::Edition => (string) $p['editionName'],
                AccountingColumn::Category => $category,
                AccountingColumn::Role => $role === null ? '' : $this->t('plugins.generic.conferenceManager.registration.participantRole.' . $role, [], $locale),
                AccountingColumn::SubmissionId => (string) ($invoice->items[0]->submissionId ?? ''),
                AccountingColumn::Description => implode('; ', array_map(static fn ($i): string => $i->description, $invoice->items)),
                AccountingColumn::Total => $invoice->total->decimal(),
                AccountingColumn::Paid => $invoice->paidAmount()->decimal(),
                AccountingColumn::Due => $invoice->due()->decimal(),
                AccountingColumn::Currency => $invoice->total->currency,
                AccountingColumn::Mode => (string) $p['modeLabel'],
                AccountingColumn::PaymentReference => (string) $invoice->paymentReference,
                AccountingColumn::Purpose => (string) $p['purpose'],
                AccountingColumn::Account => (string) ($account['iban'] ?? ''),
                AccountingColumn::WaiverReason => (string) $invoice->waiverReason,
                AccountingColumn::Refunded => $invoice->refundedAmount()->decimal(),
                AccountingColumn::RefundedAt => $date($invoice->refundedAt),
                AccountingColumn::RefundNote => (string) $invoice->refundNote,
                AccountingColumn::Supersedes => $invoice->supersedesId === null ? '' : (string) ($this->payments->findInvoice($context->id, $invoice->supersedesId)->number ?? $invoice->supersedesId),
            };
        }
        return $out;
    }

    /** null = no limit; false = not a date. */
    private static function day(mixed $v): DateTimeImmutable|false|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d') === $v ? $d : false;
    }

    /** @param array<string, mixed> $params */
    private function t(string $key, array $params, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, $params, $locale);
    }
}
