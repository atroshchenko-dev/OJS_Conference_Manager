<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;

/**
 * "Category → payment mode" of an edition (PAY-06). The rule without a
 * category applies to every category that has no rule of its own.
 */
final class PaymentRule
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $editionId,
        public readonly ?int $categoryId,
        public readonly PaymentMode $mode,
        public readonly ?int $paymentAccountId = null,
        public readonly ?string $externalUrl = null,
        public readonly LocalizedText $instructions = new LocalizedText(),
    ) {
    }

    public function withId(int $id): self
    {
        return new self($id, $this->editionId, $this->categoryId, $this->mode, $this->paymentAccountId, $this->externalUrl, $this->instructions);
    }

    /**
     * The rule for a category: its own, else the default one, else null
     * (no rule means nothing to pay).
     *
     * @param list<self> $rules
     */
    public static function pick(array $rules, ?int $categoryId): ?self
    {
        $default = null;
        foreach ($rules as $rule) {
            if ($categoryId !== null && $rule->categoryId === $categoryId) {
                return $rule;
            }
            if ($rule->categoryId === null) {
                $default = $rule;
            }
        }
        return $default;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'editionId' => $this->editionId,
            'categoryId' => $this->categoryId,
            'mode' => $this->mode->value,
            'paymentAccountId' => $this->paymentAccountId,
            'externalUrl' => $this->externalUrl,
            'instructions' => $this->instructions->toArray(),
        ];
    }
}
