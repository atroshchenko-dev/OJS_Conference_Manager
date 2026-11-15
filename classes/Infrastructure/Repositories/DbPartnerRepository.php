<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Partner\Partner;
use APP\plugins\generic\conferenceManager\classes\Domain\Partner\PartnerRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Partner\PartnerRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use Illuminate\Database\Connection;

/**
 * INS-04 on conf_edition_partners (Step0003): the name is localized in
 * conf_edition_partner_settings, the address of the site has locale ''.
 */
final class DbPartnerRepository implements PartnerRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function ofEdition(int $editionId): array
    {
        $rows = $this->db->table('conf_edition_partners')->where('edition_id', $editionId)->orderBy('seq')->orderBy('id')->get()->all();
        if ($rows === []) {
            return [];
        }
        $settings = [];
        foreach ($this->db->table('conf_edition_partner_settings')
            ->whereIn('partner_id', array_map(static fn (object $r): int => (int) $r->id, $rows))->get() as $s) {
            $settings[(int) $s->partner_id][(string) $s->setting_name][(string) $s->locale] = (string) $s->setting_value;
        }
        return array_map(static fn (object $r): Partner => new Partner(
            (int) $r->id,
            (int) $r->edition_id,
            PartnerRole::tryFrom((string) $r->role) ?? PartnerRole::Partner,
            new LocalizedText($settings[(int) $r->id]['name'] ?? []),
            (string) ($settings[(int) $r->id]['url'][''] ?? ''),
            $r->logo_path === null ? null : (string) $r->logo_path,
            $r->payment_account_id === null ? null : (int) $r->payment_account_id,
            (int) $r->seq,
        ), $rows);
    }

    public function find(int $editionId, int $id): ?Partner
    {
        foreach ($this->ofEdition($editionId) as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }
        return null;
    }

    public function save(Partner $partner): Partner
    {
        return $this->db->transaction(function () use ($partner): Partner {
            $now = gmdate('Y-m-d H:i:s');
            $row = [
                'seq' => $partner->seq,
                'role' => $partner->role->value,
                'logo_path' => $partner->logoPath,
                'payment_account_id' => $partner->paymentAccountId,
                'updated_at' => $now,
            ];
            if ($partner->id === null) {
                $id = (int) $this->db->table('conf_edition_partners')->insertGetId($row + ['edition_id' => $partner->editionId, 'created_at' => $now]);
            } else {
                $id = $partner->id;
                $this->db->table('conf_edition_partners')->where('id', $id)->where('edition_id', $partner->editionId)->update($row);
            }
            $this->db->table('conf_edition_partner_settings')->where('partner_id', $id)->delete();
            $rows = [];
            foreach ($partner->name->toArray() as $locale => $text) {
                $rows[] = ['partner_id' => $id, 'locale' => $locale, 'setting_name' => 'name', 'setting_value' => $text];
            }
            if ($partner->url !== '') {
                $rows[] = ['partner_id' => $id, 'locale' => '', 'setting_name' => 'url', 'setting_value' => $partner->url];
            }
            if ($rows !== []) {
                $this->db->table('conf_edition_partner_settings')->insert($rows);
            }
            return $partner->withId($id);
        });
    }

    public function delete(int $editionId, int $id): bool
    {
        return $this->db->table('conf_edition_partners')->where('edition_id', $editionId)->where('id', $id)->delete() > 0;
    }
}
