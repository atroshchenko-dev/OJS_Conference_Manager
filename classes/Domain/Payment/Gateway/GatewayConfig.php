<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

/** PAY-09: the journal's own merchant account at one gateway. */
final class GatewayConfig
{
    /**
     * @param array<string, string> $values field name => value, secrets included
     */
    public function __construct(
        public readonly string $gatewayId,
        public readonly bool $sandbox,
        public readonly array $values,
    ) {
    }

    public function value(string $name): string
    {
        return $this->values[$name] ?? '';
    }
}
