<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\Iban;

/**
 * Account that receives fees (INS-03). An institution can have several:
 * a bank account, a treasury account, an account of a co-organizer.
 */
final class PaymentAccount
{
    /** Variables allowed in the payment purpose template. */
    public const PURPOSE_VARIABLES = [
        'invoiceNumber',
        'paymentReference',
        'payerName',
        'editionTitle',
        'editionPath',
        'year',
        'amount',
        'currency',
    ];

    /**
     * @param array<string, scalar|null> $extra jurisdiction-specific fields
     */
    public function __construct(
        public readonly ?int $id,
        public readonly PaymentAccountKind $kind,
        public readonly string $label,
        public readonly string $recipientName,
        public readonly string $iban,
        public readonly string $bankName = '',
        public readonly string $legalId = '',
        public readonly string $purposeTemplate = '',
        public readonly array $extra = [],
        public readonly bool $isActive = true,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'recipientName' => $this->recipientName,
            'iban' => $this->iban,
            'bankName' => $this->bankName,
            'legalId' => $this->legalId,
            'purposeTemplate' => $this->purposeTemplate,
            'extra' => $this->extra,
            'isActive' => $this->isActive,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $extra = [];
        foreach ((array) ($data['extra'] ?? []) as $k => $v) {
            if (is_string($k) && (is_scalar($v) || $v === null)) {
                $extra[$k] = $v;
            }
        }
        return new self(
            isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null,
            PaymentAccountKind::tryFrom((string) ($data['kind'] ?? '')) ?? PaymentAccountKind::Bank,
            trim((string) ($data['label'] ?? '')),
            trim((string) ($data['recipientName'] ?? '')),
            Iban::normalize((string) ($data['iban'] ?? '')),
            trim((string) ($data['bankName'] ?? '')),
            trim((string) ($data['legalId'] ?? '')),
            trim((string) ($data['purposeTemplate'] ?? '')),
            $extra,
            filter_var($data['isActive'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }
}
