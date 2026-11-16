<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;

/** PAY-09: what the participant pays in one attempt. */
final class CheckoutOrder
{
    public function __construct(
        /** Unique for the gateway account: invoice number and attempt. */
        public readonly string $orderId,
        public readonly Money $amount,
        public readonly string $description,
        /** Where the participant comes back after paying. */
        public readonly string $returnUrl,
        /** Where the gateway reports the result, server to server. */
        public readonly string $callbackUrl,
        public readonly string $language,
    ) {
    }
}
