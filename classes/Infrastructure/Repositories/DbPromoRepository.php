<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PromoCode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PromoRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/** REG-11 on conf_promo_codes and conf_promo_redemptions (Step0016). */
final class DbPromoRepository implements PromoRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function ofEdition(int $editionId): array
    {
        $rows = $this->db->table('conf_promo_codes')->where('edition_id', $editionId)->orderBy('code')->get()->all();
        $uses = $this->uses(array_map(static fn (object $r): int => (int) $r->id, $rows));
        return array_map(fn (object $r): PromoCode => $this->hydrate($r, $uses[(int) $r->id] ?? 0), $rows);
    }

    public function find(int $editionId, int $id): ?PromoCode
    {
        $row = $this->db->table('conf_promo_codes')->where('edition_id', $editionId)->where('id', $id)->first();
        return $row === null ? null : $this->hydrate($row, $this->uses([$id])[$id] ?? 0);
    }

    public function findByCode(int $editionId, string $code): ?PromoCode
    {
        $row = $this->db->table('conf_promo_codes')->where('edition_id', $editionId)->where('code', PromoCode::normalize($code))->first();
        return $row === null ? null : $this->hydrate($row, $this->uses([(int) $row->id])[(int) $row->id] ?? 0);
    }

    public function save(PromoCode $code): PromoCode
    {
        $row = [
            'edition_id' => $code->editionId,
            'code' => $code->code,
            'percent' => $code->percent,
            'amount_minor' => $code->amount?->minor,
            'currency' => $code->amount?->currency,
            'max_uses' => $code->maxUses,
            'valid_from' => $code->validFrom,
            'valid_until' => $code->validUntil,
            'active' => $code->active,
            'note' => $code->note === '' ? null : $code->note,
        ];
        if ($code->id === null) {
            $id = (int) $this->db->table('conf_promo_codes')->insertGetId($row + ['created_at' => gmdate('Y-m-d H:i:s')]);
            return $code->withId($id);
        }
        $this->db->table('conf_promo_codes')->where('id', $code->id)->where('edition_id', $code->editionId)->update($row);
        return $code;
    }

    public function delete(int $editionId, int $id): bool
    {
        return $this->db->table('conf_promo_codes')->where('edition_id', $editionId)->where('id', $id)->delete() > 0;
    }

    public function everUsed(int $id): bool
    {
        return $this->db->table('conf_promo_redemptions')->where('promo_code_id', $id)->exists();
    }

    public function redeem(int $codeId, int $participationId, DateTimeImmutable $now): bool
    {
        return $this->db->transaction(function () use ($codeId, $participationId, $now): bool {
            $row = $this->db->table('conf_promo_codes')->where('id', $codeId)->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $current = $this->db->table('conf_promo_redemptions')->where('participation_id', $participationId)->first();
            if ($current !== null && (int) $current->promo_code_id === $codeId) {
                return true;
            }
            if ($row->max_uses !== null && ($this->uses([$codeId])[$codeId] ?? 0) >= (int) $row->max_uses) {
                return false;
            }
            $this->db->table('conf_promo_redemptions')->updateOrInsert(
                ['participation_id' => $participationId],
                ['promo_code_id' => $codeId, 'created_at' => DateInput::toStorage($now)],
            );
            return true;
        });
    }

    public function ofParticipation(int $participationId): ?PromoCode
    {
        $row = $this->db->table('conf_promo_redemptions as r')
            ->join('conf_promo_codes as c', 'c.id', '=', 'r.promo_code_id')
            ->where('r.participation_id', $participationId)
            ->select('c.*')->first();
        return $row === null ? null : $this->hydrate($row, $this->uses([(int) $row->id])[(int) $row->id] ?? 0);
    }

    /**
     * Uses by participations that are not cancelled: a cancelled
     * registration gives its use back.
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    private function uses(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('conf_promo_redemptions as r')
            ->join('conf_participations as pn', 'pn.id', '=', 'r.participation_id')
            ->whereIn('r.promo_code_id', $ids)
            ->where('pn.status', '!=', ParticipationStatus::Cancelled->value)
            ->groupBy('r.promo_code_id')
            ->selectRaw('r.promo_code_id as id, count(*) as n')->get() as $r) {
            $out[(int) $r->id] = (int) $r->n;
        }
        return $out;
    }

    private function hydrate(object $r, int $uses): PromoCode
    {
        return new PromoCode(
            (int) $r->id,
            (int) $r->edition_id,
            (string) $r->code,
            $r->percent === null ? null : (int) $r->percent,
            $r->amount_minor === null || $r->currency === null ? null : new Money((int) $r->amount_minor, (string) $r->currency),
            $r->max_uses === null ? null : (int) $r->max_uses,
            $r->valid_from === null ? null : substr((string) $r->valid_from, 0, 10),
            $r->valid_until === null ? null : substr((string) $r->valid_until, 0, 10),
            (bool) $r->active,
            (string) ($r->note ?? ''),
            $uses,
        );
    }
}
