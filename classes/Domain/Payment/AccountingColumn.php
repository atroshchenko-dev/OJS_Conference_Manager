<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

/**
 * Columns of the export for accounting (FEE-12). The journal picks which
 * ones and in what order; accountants of different institutions want
 * different sets, so nothing here is tied to one of them.
 */
enum AccountingColumn: string
{
    case Number = 'number';
    case IssuedAt = 'issuedAt';
    case DueAt = 'dueAt';
    case PaidAt = 'paidAt';
    case Status = 'status';
    case PayerName = 'payerName';
    case PayerEmail = 'payerEmail';
    case PayerAffiliation = 'payerAffiliation';
    /** JUR-05: ЄДРПОУ of an organisation that pays, or the personal tax number when asked. */
    case PayerCode = 'payerCode';
    case Edition = 'edition';
    case Category = 'category';
    case Role = 'role';
    case SubmissionId = 'submissionId';
    case Description = 'description';
    case Total = 'total';
    case Paid = 'paid';
    case Due = 'due';
    case Currency = 'currency';
    case Mode = 'mode';
    case PaymentReference = 'paymentReference';
    case Purpose = 'purpose';
    case Account = 'account';
    case WaiverReason = 'waiverReason';
    case Supersedes = 'supersedes';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.export.column.' . $this->value;
    }

    /** An amount: a number cell in a spreadsheet. */
    public function isAmount(): bool
    {
        return in_array($this, [self::Total, self::Paid, self::Due], true);
    }

    /** @return list<self> */
    public static function defaults(): array
    {
        return [
            self::Number, self::IssuedAt, self::PaidAt, self::Status, self::PayerName, self::PayerAffiliation,
            self::Edition, self::Category, self::Total, self::Paid, self::Due, self::Currency, self::PaymentReference,
        ];
    }

    /**
     * Known columns of a saved or sent list, in its order, without repeats.
     *
     * @param array<mixed> $values
     * @return list<self>
     */
    public static function parseList(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $c = is_string($v) ? self::tryFrom($v) : null;
            if ($c !== null && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }
}
