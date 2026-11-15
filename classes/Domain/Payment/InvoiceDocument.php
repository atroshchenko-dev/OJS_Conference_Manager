<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\TextTemplate;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The HTML of an invoice document (FEE-06, PAY-07): a template with
 * "{variable}" placeholders filled from InvoiceService::present().
 * Every value is HTML-escaped; only the two generated blocks
 * {itemsTable} and {accountFieldsRows} are HTML, built here from
 * escaped values.
 */
final class InvoiceDocument
{
    public const KIND_BANK = 'bank';
    public const KIND_TREASURY = 'treasury';
    public const KINDS = [self::KIND_BANK, self::KIND_TREASURY];
    public const MAX_LENGTH = 100000;

    public const VARIABLES = [
        'invoiceNumber', 'paymentReference', 'issueDate', 'dueDate',
        'institutionName', 'institutionAddress', 'recipientName', 'recipientLegalId', 'iban', 'bankName',
        'payerName', 'payerEmail', 'payerAffiliation', 'editionTitle',
        'purpose', 'total', 'currency', 'totalInWords', 'instructions',
        'signerName', 'signerPosition', 'itemsTable', 'accountFieldsRows',
    ];

    public static function kindFor(PaymentMode $mode): ?string
    {
        return match ($mode) {
            PaymentMode::BankTransfer => self::KIND_BANK,
            PaymentMode::TreasuryTransfer => self::KIND_TREASURY,
            default => null,
        };
    }

    /**
     * A template of the context: not too long, only known variables.
     */
    public static function validate(string $html): ValidationErrors
    {
        $errors = new ValidationErrors();
        if (strlen($html) > self::MAX_LENGTH) {
            $errors->add('html', 'plugins.generic.conferenceManager.invoiceTemplate.error.tooLong');
        } elseif (($unknown = (new TextTemplate($html))->unknownVariables(self::VARIABLES)) !== []) {
            $errors->add('html', 'plugins.generic.conferenceManager.invoiceTemplate.error.variables');
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $invoice InvoiceService::present()
     * @param array{no: string, description: string, quantity: string, price: string, amount: string, total: string} $labels
     * @param string $separator decimal separator of the document language
     */
    public static function render(string $html, array $invoice, array $labels, string $separator = '.'): string
    {
        $money = static fn (mixed $m): string => str_replace('.', $separator, (string) (((array) $m)['decimal'] ?? ''));
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $recipient = (array) ($invoice['recipient'] ?? []);
        $account = (array) ($recipient['account'] ?? []);
        $payer = (array) ($invoice['payer'] ?? []);
        $total = (array) ($invoice['total'] ?? []);
        $tz = (string) ($invoice['timezone'] ?? 'UTC');

        $values = [
            'invoiceNumber' => $invoice['number'] ?? '',
            'paymentReference' => $invoice['paymentReference'] ?? '',
            'issueDate' => self::date($invoice['issuedAt'] ?? null, $tz),
            'dueDate' => self::date($invoice['dueAt'] ?? null, $tz),
            'institutionName' => $recipient['institution'] ?? '',
            'institutionAddress' => $recipient['address'] ?? '',
            'recipientName' => ($account['recipientName'] ?? '') !== '' ? $account['recipientName'] : ($recipient['institution'] ?? ''),
            'recipientLegalId' => ($account['legalId'] ?? '') !== '' ? $account['legalId'] : ($recipient['institutionLegalId'] ?? ''),
            'iban' => self::groupIban((string) ($account['iban'] ?? '')),
            'bankName' => $account['bankName'] ?? '',
            'payerName' => $payer['name'] ?? '',
            'payerEmail' => $payer['email'] ?? '',
            'payerAffiliation' => $payer['affiliation'] ?? '',
            'editionTitle' => $invoice['editionName'] ?? '',
            'purpose' => $invoice['purpose'] ?? '',
            'total' => $money($total),
            'currency' => $total['currency'] ?? '',
            'totalInWords' => $invoice['totalInWords'] ?? '',
            'signerName' => $recipient['signerName'] ?? '',
            'signerPosition' => $recipient['signerPosition'] ?? '',
        ];
        $values = array_map($e, $values);
        $values['instructions'] = nl2br($e($invoice['instructions'] ?? ''));

        $rows = '';
        foreach ((array) ($recipient['accountFields'] ?? []) as $f) {
            $rows .= '<tr><td class="l">' . $e($f['label'] ?? '') . '</td><td>' . $e($f['value'] ?? '') . '</td></tr>';
        }
        $values['accountFieldsRows'] = $rows;

        $items = '<table class="items"><thead><tr><th>' . $e($labels['no']) . '</th><th>' . $e($labels['description']) . '</th><th>'
            . $e($labels['quantity']) . '</th><th>' . $e($labels['price']) . '</th><th>' . $e($labels['amount']) . '</th></tr></thead><tbody>';
        foreach (array_values((array) ($invoice['items'] ?? [])) as $i => $item) {
            $items .= '<tr><td class="r">' . ($i + 1) . '</td><td>' . $e($item['description'] ?? '') . '</td><td class="r">' . $e($item['quantity'] ?? 1)
                . '</td><td class="r">' . $e($money($item['unit'] ?? [])) . '</td><td class="r">' . $e($money($item['amount'] ?? [])) . '</td></tr>';
        }
        $items .= '<tr><td colspan="4" class="r"><b>' . $e($labels['total']) . '</b></td><td class="r"><b>' . $values['total'] . ' ' . $values['currency'] . '</b></td></tr></tbody></table>';
        $values['itemsTable'] = $items;

        $out = (new TextTemplate($html))->render($values);
        // A requisite row left without a value is not printed.
        return (string) preg_replace('#<tr>\s*<td class="l">[^<]*</td>\s*<td>\s*(?:<br>\s*)?</td>\s*</tr>\s*#u', '', $out);
    }

    private static function date(mixed $iso, string $tz): string
    {
        if (!is_string($iso) || $iso === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($tz))->format('d.m.Y');
        } catch (\Exception) {
            return '';
        }
    }

    private static function groupIban(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }
}
