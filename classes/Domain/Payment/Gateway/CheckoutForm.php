<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

/** PAY-09: the form the participant's browser posts to the gateway's payment page. */
final class CheckoutForm
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public readonly string $action,
        public readonly array $fields,
    ) {
    }
}
