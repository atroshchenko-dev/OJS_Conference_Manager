<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\PaymentGateway;

/** PAY-09: the payment gateways known to the plugin, by id. */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    /**
     * @param iterable<mixed> $gateways anything else than a gateway is skipped
     */
    public function __construct(iterable $gateways)
    {
        foreach ($gateways as $gateway) {
            if ($gateway instanceof PaymentGateway && preg_match('/^[a-z][a-z0-9_]{1,31}$/', $gateway->id())) {
                $this->gateways[$gateway->id()] ??= $gateway;
            }
        }
    }

    public function get(string $id): ?PaymentGateway
    {
        return $this->gateways[$id] ?? null;
    }

    /** @return list<PaymentGateway> */
    public function all(): array
    {
        return array_values($this->gateways);
    }
}
