<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use DateTimeImmutable;

/** PAY-09: one attempt to pay an invoice by card. */
final class GatewayPayment
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $contextId,
        public readonly int $invoiceId,
        public readonly string $gateway,
        public readonly string $orderId,
        public readonly Money $amount,
        public readonly GatewayStatus $status,
        public readonly ?string $transactionId,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
