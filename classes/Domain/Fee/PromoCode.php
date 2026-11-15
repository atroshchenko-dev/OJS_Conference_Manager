<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

/**
 * REG-11: a promo code of an edition. It lowers the fee by a percentage or
 * by a fixed amount, may be limited to a number of uses and to a period
 * (dates in the time zone of the edition, both included).
 */
final class PromoCode
{
    public const MAX_PER_EDITION = 200;

    private const K = 'plugins.generic.conferenceManager.promo.error.';

    public function __construct(
        public readonly ?int $id,
        public readonly int $editionId,
        public readonly string $code,
        public readonly ?int $percent,
        public readonly ?Money $amount,
        public readonly ?int $maxUses = null,
        public readonly ?string $validFrom = null,
        public readonly ?string $validUntil = null,
        public readonly bool $active = true,
        public readonly string $note = '',
        /** Uses by participations that are not cancelled. */
        public readonly int $uses = 0,
    ) {
    }

    /** What people type: case, spaces and dashes around do not matter. */
    public static function normalize(string $code): string
    {
        return strtoupper(trim(preg_replace('/\s+/u', '', $code) ?? '', '-'));
    }

    /**
     * Builds a code from untrusted input; errors name the fields.
     *
     * @param array<string, mixed> $input code, discountType (percent|amount), percent, amount, currency, maxUses, validFrom, validUntil, active, note
     */
    public static function fromInput(?int $id, int $editionId, array $input, ValidationErrors $errors): ?self
    {
        $code = self::normalize((string) ($input['code'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $code)) {
            $errors->add('code', self::K . 'code');
        }
        $percent = null;
        $amount = null;
        if (($input['discountType'] ?? 'percent') === 'amount') {
            $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
            $amount = Money::isCurrency($currency) ? Money::parse((string) ($input['amount'] ?? ''), $currency) : null;
            if ($amount === null || $amount->isZero()) {
                $errors->add('amount', self::K . 'amount');
            }
        } else {
            $percent = filter_var($input['percent'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($percent === false) {
                $errors->add('percent', self::K . 'percent');
                $percent = null;
            }
        }
        $maxUses = null;
        if (($input['maxUses'] ?? '') !== '' && ($input['maxUses'] ?? null) !== null) {
            $maxUses = filter_var($input['maxUses'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            if ($maxUses === false) {
                $errors->add('maxUses', self::K . 'maxUses');
                $maxUses = null;
            }
        }
        $from = self::date($input['validFrom'] ?? null, 'validFrom', $errors);
        $until = self::date($input['validUntil'] ?? null, 'validUntil', $errors);
        if ($from !== null && $until !== null && $until < $from) {
            $errors->add('validUntil', self::K . 'period');
        }
        $note = mb_substr(trim((string) ($input['note'] ?? '')), 0, 255);
        if (!$errors->isEmpty()) {
            return null;
        }
        return new self($id, $editionId, $code, $percent, $amount, $maxUses, $from, $until, filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN), $note);
    }

    private static function date(mixed $value, string $field, ValidationErrors $errors): ?string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            $errors->add($field, self::K . 'date');
            return null;
        }
        return $s;
    }

    /** Why the code cannot be used on this day (Y-m-d in the edition's zone), or null. */
    public function problemOn(string $day): ?string
    {
        if (!$this->active) {
            return self::K . 'inactive';
        }
        if ($this->validFrom !== null && $day < $this->validFrom) {
            return self::K . 'notYet';
        }
        if ($this->validUntil !== null && $day > $this->validUntil) {
            return self::K . 'expired';
        }
        if ($this->maxUses !== null && $this->uses >= $this->maxUses) {
            return self::K . 'usedUp';
        }
        return null;
    }

    /**
     * The fee after the discount, never below zero. A fixed amount in
     * another currency than the fee does not apply.
     */
    public function apply(Money $total): Money
    {
        if ($this->percent !== null) {
            // Rounded to the minor unit in favour of the payer.
            $discount = intdiv($total->minor * $this->percent + 99, 100);
            return new Money(max(0, $total->minor - $discount), $total->currency);
        }
        if ($this->amount !== null && $this->amount->currency === $total->currency) {
            return new Money(max(0, $total->minor - $this->amount->minor), $total->currency);
        }
        return $total;
    }

    /** "20 %" or "200.00 UAH". */
    public function discountText(): string
    {
        return $this->percent !== null ? $this->percent . ' %' : ($this->amount === null ? '' : $this->amount->decimal() . ' ' . $this->amount->currency);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->editionId, $this->code, $this->percent, $this->amount, $this->maxUses, $this->validFrom, $this->validUntil, $this->active, $this->note, $this->uses);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'discountType' => $this->percent !== null ? 'percent' : 'amount',
            'percent' => $this->percent,
            'amount' => $this->amount?->toArray(),
            'discount' => $this->discountText(),
            'maxUses' => $this->maxUses,
            'uses' => $this->uses,
            'validFrom' => $this->validFrom,
            'validUntil' => $this->validUntil,
            'active' => $this->active,
            'note' => $this->note,
        ];
    }
}
