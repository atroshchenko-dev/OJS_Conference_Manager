<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

/**
 * Result of TariffCalculator: the amount and the tariff it came from,
 * or the reason why there is no amount.
 */
final class Quote
{
    public const NO_TARIFF = 'noTariff';
    public const PAGES_REQUIRED = 'pagesRequired';

    private function __construct(
        public readonly ?Money $total,
        public readonly ?Tariff $tariff,
        public readonly ?int $pages,
        public readonly ?string $problem,
    ) {
    }

    public static function of(Money $total, Tariff $tariff, ?int $pages): self
    {
        return new self($total, $tariff, $pages, null);
    }

    public static function problem(string $problem, ?Tariff $tariff = null): self
    {
        return new self(null, $tariff, null, $problem);
    }

    public function isOk(): bool
    {
        return $this->total !== null;
    }

    public function isFree(): bool
    {
        return $this->total !== null && $this->total->isZero();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total' => $this->total?->toArray(),
            'tariffId' => $this->tariff?->id,
            'pricing' => $this->tariff?->pricing->value,
            'unit' => $this->tariff?->amount->toArray(),
            'pages' => $this->pages,
            'problem' => $this->problem,
        ];
    }
}
