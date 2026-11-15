<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

/**
 * The only place where fees are calculated (FEE-03).
 *
 * Choosing the tariff:
 *  1. tariffs of the category for this material; if there are none, those for "any";
 *  2. valid at the moment of the query, matching the presentation type (or for all types)
 *     and covering the number of pages (or without a page range);
 *  3. the most specific wins: for this presentation type, then with a page range,
 *     then with a period of validity (early prices), then the shorter period,
 *     then the earlier tariff.
 * A flat tariff gives its amount; a per-page tariff gives amount × pages.
 */
final class TariffCalculator
{
    /**
     * @param list<Tariff> $tariffs tariffs of the edition (any categories)
     */
    public function quote(array $tariffs, TariffQuery $query): Quote
    {
        $ofCategory = array_values(array_filter($tariffs, static fn (Tariff $t): bool => $t->categoryId === $query->categoryId));
        $candidates = array_values(array_filter($ofCategory, static fn (Tariff $t): bool => $t->material === $query->material));
        if ($candidates === [] && $query->material !== MaterialType::Any) {
            $candidates = array_values(array_filter($ofCategory, static fn (Tariff $t): bool => $t->material === MaterialType::Any));
        }

        $matching = array_values(array_filter($candidates, static fn (Tariff $t): bool => $t->isValidAt($query->at)
            && ($t->presentationType === null || $t->presentationType === $query->presentationType)
            && $t->coversPages($query->pages)));

        if ($matching === []) {
            // A per-page or page-range tariff exists but the pages are unknown.
            foreach ($candidates as $t) {
                if ($query->pages === null && $t->isValidAt($query->at) && ($t->pricing === PricingMode::PerPage || $t->hasPageRange())) {
                    return Quote::problem(Quote::PAGES_REQUIRED, $t);
                }
            }
            return Quote::problem(Quote::NO_TARIFF);
        }

        usort($matching, static fn (Tariff $a, Tariff $b): int => self::rank($b) <=> self::rank($a) ?: ($a->id ?? PHP_INT_MAX) <=> ($b->id ?? PHP_INT_MAX));
        $tariff = $matching[0];

        if ($tariff->pricing === PricingMode::PerPage) {
            if ($query->pages === null || $query->pages < 1) {
                return Quote::problem(Quote::PAGES_REQUIRED, $tariff);
            }
            return Quote::of($tariff->amount->times($query->pages), $tariff, $query->pages);
        }
        return Quote::of($tariff->amount, $tariff, $query->pages);
    }

    /**
     * @return list<int> compared element by element, bigger is more specific
     */
    private static function rank(Tariff $t): array
    {
        $bounded = $t->validFrom !== null || $t->validTo !== null;
        $length = ($t->validFrom !== null && $t->validTo !== null)
            ? $t->validTo->getTimestamp() - $t->validFrom->getTimestamp()
            : PHP_INT_MAX;
        return [
            $t->presentationType !== null ? 1 : 0,
            $t->hasPageRange() ? 1 : 0,
            $bounded ? 1 : 0,
            -min($length, PHP_INT_MAX),
        ];
    }
}
