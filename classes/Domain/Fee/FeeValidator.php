<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

/**
 * Rules for categories and tariffs (FEE-01, FEE-02).
 */
final class FeeValidator
{
    private const K = 'plugins.generic.conferenceManager.fee.error.';
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    /**
     * @param list<FeeCategory> $others other categories of the same edition
     */
    public function validateCategory(FeeCategory $category, string $primaryLocale, array $others): ValidationErrors
    {
        $errors = new ValidationErrors();
        if (!preg_match(self::CODE_PATTERN, $category->code)) {
            $errors->add('code', self::K . 'code');
        } else {
            foreach ($others as $other) {
                if ($other->id !== $category->id && $other->code === $category->code) {
                    $errors->add('code', self::K . 'codeTaken');
                    break;
                }
            }
        }
        if ($category->name->get($primaryLocale) === null) {
            $errors->add('name', self::K . 'nameRequired');
        }
        return $errors;
    }

    /**
     * @param list<Tariff> $others other tariffs of the edition
     */
    public function validateTariff(Tariff $tariff, array $others): ValidationErrors
    {
        $errors = new ValidationErrors();
        if ($tariff->amount->minor < 0) {
            $errors->add('amount', self::K . 'amount');
        }
        if (($tariff->minPages !== null && $tariff->minPages < 1) || ($tariff->maxPages !== null && $tariff->maxPages < 1)
            || ($tariff->minPages !== null && $tariff->maxPages !== null && $tariff->maxPages < $tariff->minPages)) {
            $errors->add('maxPages', self::K . 'pages');
        }
        if ($tariff->validFrom !== null && $tariff->validTo !== null && $tariff->validTo <= $tariff->validFrom) {
            $errors->add('validTo', self::K . 'period');
        }
        if ($tariff->material === MaterialType::Listener && ($tariff->pricing === PricingMode::PerPage || $tariff->hasPageRange() || $tariff->presentationType !== null)) {
            $errors->add('pricing', self::K . 'listenerFlat');
        }
        foreach ($others as $other) {
            if ($other->id !== $tariff->id && self::sameCell($tariff, $other) && self::overlaps($tariff, $other)) {
                $errors->add('validFrom', self::K . 'overlap');
                break;
            }
        }
        return $errors;
    }

    /** Two tariffs the calculator could not tell apart. */
    private static function sameCell(Tariff $a, Tariff $b): bool
    {
        return $a->categoryId === $b->categoryId
            && $a->material === $b->material
            && $a->presentationType === $b->presentationType
            && $a->minPages === $b->minPages
            && $a->maxPages === $b->maxPages
            && ($a->validFrom === null) === ($b->validFrom === null)
            && ($a->validTo === null) === ($b->validTo === null);
    }

    private static function overlaps(Tariff $a, Tariff $b): bool
    {
        $aFrom = $a->validFrom?->getTimestamp() ?? PHP_INT_MIN;
        $aTo = $a->validTo?->getTimestamp() ?? PHP_INT_MAX;
        $bFrom = $b->validFrom?->getTimestamp() ?? PHP_INT_MIN;
        $bTo = $b->validTo?->getTimestamp() ?? PHP_INT_MAX;
        return $aFrom < $bTo && $bFrom < $aTo;
    }
}
