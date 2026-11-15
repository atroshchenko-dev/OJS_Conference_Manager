<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeCategory;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\MaterialType;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PricingMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Tariff;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use Illuminate\Database\Connection;

final class DbFeeRepository implements FeeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function categories(int $editionId): array
    {
        $rows = $this->db->table('conf_fee_categories')->where('edition_id', $editionId)
            ->orderBy('sort_order')->orderBy('id')->get()->all();
        if ($rows === []) {
            return [];
        }
        $names = [];
        foreach ($this->db->table('conf_fee_category_settings')
            ->whereIn('category_id', array_map(static fn (object $r): int => (int) $r->id, $rows))
            ->where('setting_name', 'name')->get() as $s) {
            $names[(int) $s->category_id][(string) $s->locale] = (string) $s->setting_value;
        }
        return array_map(static fn (object $r): FeeCategory => new FeeCategory(
            (int) $r->id,
            (int) $r->edition_id,
            (string) $r->code,
            new LocalizedText($names[(int) $r->id] ?? []),
            (bool) $r->requires_proof,
            (int) $r->sort_order,
        ), $rows);
    }

    public function findCategory(int $editionId, int $id): ?FeeCategory
    {
        foreach ($this->categories($editionId) as $c) {
            if ($c->id === $id) {
                return $c;
            }
        }
        return null;
    }

    public function saveCategory(FeeCategory $category): FeeCategory
    {
        return $this->db->transaction(function () use ($category): FeeCategory {
            $now = gmdate('Y-m-d H:i:s');
            $row = [
                'code' => $category->code,
                'requires_proof' => $category->requiresProof,
                'sort_order' => $category->sortOrder,
                'updated_at' => $now,
            ];
            if ($category->id === null) {
                $id = (int) $this->db->table('conf_fee_categories')->insertGetId($row + ['edition_id' => $category->editionId, 'created_at' => $now]);
            } else {
                $id = $category->id;
                $this->db->table('conf_fee_categories')->where('id', $id)->where('edition_id', $category->editionId)->update($row);
            }
            $this->db->table('conf_fee_category_settings')->where('category_id', $id)->where('setting_name', 'name')->delete();
            foreach ($category->name->toArray() as $locale => $text) {
                $this->db->table('conf_fee_category_settings')->insert([
                    'category_id' => $id, 'locale' => $locale, 'setting_name' => 'name', 'setting_value' => $text,
                ]);
            }
            return $category->withId($id);
        });
    }

    public function deleteCategory(int $editionId, int $id): bool
    {
        return $this->db->table('conf_fee_categories')->where('edition_id', $editionId)->where('id', $id)->delete() > 0;
    }

    public function tariffs(int $editionId): array
    {
        $rows = $this->db->table('conf_tariffs as t')
            ->join('conf_fee_categories as c', 'c.id', '=', 't.category_id')
            ->where('c.edition_id', $editionId)
            ->orderBy('c.sort_order')->orderBy('t.category_id')->orderBy('t.id')
            ->get(['t.*'])->all();
        $out = [];
        foreach ($rows as $r) {
            $material = MaterialType::tryFrom((string) $r->material_type);
            $pricing = PricingMode::tryFrom((string) $r->pricing_mode);
            if ($material === null || $pricing === null || !Money::isCurrency((string) $r->currency)) {
                continue;
            }
            $out[] = new Tariff(
                (int) $r->id,
                (int) $r->category_id,
                $material,
                $pricing,
                new Money((int) $r->amount_minor, (string) $r->currency),
                $r->presentation_type === null ? null : PresentationType::tryFrom((string) $r->presentation_type),
                $r->min_pages === null ? null : (int) $r->min_pages,
                $r->max_pages === null ? null : (int) $r->max_pages,
                DateInput::fromStorage($r->valid_from === null ? null : (string) $r->valid_from),
                DateInput::fromStorage($r->valid_to === null ? null : (string) $r->valid_to),
            );
        }
        return $out;
    }

    public function findTariff(int $editionId, int $id): ?Tariff
    {
        foreach ($this->tariffs($editionId) as $t) {
            if ($t->id === $id) {
                return $t;
            }
        }
        return null;
    }

    public function saveTariff(Tariff $tariff): Tariff
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'category_id' => $tariff->categoryId,
            'material_type' => $tariff->material->value,
            'presentation_type' => $tariff->presentationType?->value,
            'pricing_mode' => $tariff->pricing->value,
            'amount_minor' => $tariff->amount->minor,
            'currency' => $tariff->amount->currency,
            'min_pages' => $tariff->minPages,
            'max_pages' => $tariff->maxPages,
            'valid_from' => DateInput::toStorage($tariff->validFrom),
            'valid_to' => DateInput::toStorage($tariff->validTo),
            'updated_at' => $now,
        ];
        if ($tariff->id === null) {
            return $tariff->withId((int) $this->db->table('conf_tariffs')->insertGetId($row + ['created_at' => $now]));
        }
        $this->db->table('conf_tariffs')->where('id', $tariff->id)->update($row);
        return $tariff;
    }

    public function deleteTariff(int $editionId, int $id): bool
    {
        $categoryIds = $this->db->table('conf_fee_categories')->where('edition_id', $editionId)->pluck('id')->all();
        return $categoryIds !== [] && $this->db->table('conf_tariffs')->where('id', $id)->whereIn('category_id', $categoryIds)->delete() > 0;
    }

    public function categoryInUse(int $id): bool
    {
        return $this->db->table('conf_participants')->where('category_id', $id)->exists();
    }
}
