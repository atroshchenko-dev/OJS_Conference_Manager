<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

/** PAY-09: a verified report of the gateway about one attempt. */
final class GatewayResult
{
    /**
     * @param array<string, mixed> $details what is kept in the payment journal (no card data)
     */
    public function __construct(
        public readonly string $orderId,
        public readonly GatewayStatus $status,
        /** Amount in minor units, as the gateway reports it. */
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $transactionId = null,
        public readonly array $details = [],
    ) {
    }
}
