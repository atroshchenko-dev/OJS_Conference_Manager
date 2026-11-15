<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

/**
 * Categories and tariffs of editions. Callers check that the edition
 * belongs to the context before using these methods.
 */
interface FeeRepository
{
    /** @return list<FeeCategory> ordered by sortOrder, id */
    public function categories(int $editionId): array;

    public function findCategory(int $editionId, int $id): ?FeeCategory;

    public function saveCategory(FeeCategory $category): FeeCategory;

    /** Deletes the category with its tariffs; participants keep no category. */
    public function deleteCategory(int $editionId, int $id): bool;

    /** @return list<Tariff> tariffs of all categories of the edition */
    public function tariffs(int $editionId): array;

    public function findTariff(int $editionId, int $id): ?Tariff;

    public function saveTariff(Tariff $tariff): Tariff;

    public function deleteTariff(int $editionId, int $id): bool;

    public function categoryInUse(int $id): bool;
}
