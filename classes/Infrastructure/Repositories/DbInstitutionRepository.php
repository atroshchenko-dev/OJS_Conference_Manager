<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionProfile;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccount;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\Signer;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use Illuminate\Database\ConnectionInterface;

/**
 * Stores the institution profile with the query builder. Every query is
 * bound to a context id (model C isolation, plan 7.9).
 */
final class DbInstitutionRepository implements InstitutionRepository
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function findByContextId(int $contextId): ?InstitutionProfile
    {
        $row = $this->db->table('conf_institutions')->where('context_id', $contextId)->first();
        if ($row === null) {
            return null;
        }
        $id = (int) $row->id;

        $data = [
            'jurisdiction' => $row->jurisdiction,
            'institutionType' => $row->institution_type,
            'ownership' => $row->ownership,
            'legalId' => $row->legal_id,
            'contactEmail' => $row->contact_email,
            'website' => $row->website,
            'logoPath' => $row->logo_path,
            'sealPath' => $row->seal_path,
            'extra' => self::decode($row->extra),
        ] + ($this->loadSettings('conf_institution_settings', 'institution_id', [$id])[$id] ?? []);

        $signerRows = $this->db->table('conf_signers')->where('institution_id', $id)->orderBy('seq')->orderBy('id')->get();
        $signerIds = $signerRows->pluck('id')->map(static fn ($v): int => (int) $v)->all();
        $signerSettings = $this->loadSettings('conf_signer_settings', 'signer_id', $signerIds);
        $data['signers'] = [];
        foreach ($signerRows as $s) {
            $data['signers'][] = [
                'id' => (int) $s->id,
                'signaturePath' => $s->signature_path,
                'documentTypes' => self::decode($s->document_types),
            ] + ($signerSettings[(int) $s->id] ?? []);
        }

        $data['paymentAccounts'] = [];
        foreach ($this->db->table('conf_payment_accounts')->where('institution_id', $id)->orderBy('seq')->orderBy('id')->get() as $a) {
            $data['paymentAccounts'][] = [
                'id' => (int) $a->id,
                'kind' => $a->kind,
                'label' => $a->label,
                'recipientName' => $a->recipient_name,
                'iban' => $a->iban,
                'bankName' => $a->bank_name,
                'legalId' => $a->legal_id,
                'purposeTemplate' => (string) $a->purpose_template,
                'extra' => self::decode($a->extra),
                'isActive' => (bool) $a->is_active,
            ];
        }

        return InstitutionProfile::fromArray($contextId, $data, $id);
    }

    public function save(InstitutionProfile $profile): InstitutionProfile
    {
        return $this->db->transaction(function () use ($profile): InstitutionProfile {
            $now = gmdate('Y-m-d H:i:s');
            $existingId = $this->db->table('conf_institutions')->where('context_id', $profile->contextId)->value('id');
            $row = [
                'jurisdiction' => $profile->jurisdiction,
                'institution_type' => $profile->type->value,
                'ownership' => $profile->ownership->value,
                'legal_id' => $profile->legalId,
                'contact_email' => $profile->contactEmail,
                'website' => $profile->website,
                'logo_path' => $profile->logoPath,
                'seal_path' => $profile->sealPath,
                'extra' => self::encode($profile->extra),
                'updated_at' => $now,
            ];
            if ($existingId === null) {
                $id = (int) $this->db->table('conf_institutions')->insertGetId(
                    $row + ['context_id' => $profile->contextId, 'created_at' => $now],
                );
            } else {
                $id = (int) $existingId;
                $this->db->table('conf_institutions')->where('id', $id)->update($row);
            }

            $localized = [];
            foreach (InstitutionProfile::LOCALIZED_FIELDS as $field) {
                $localized[$field] = $profile->text($field);
            }
            $this->replaceSettings('conf_institution_settings', 'institution_id', $id, $localized);

            $this->saveSigners($id, $profile->signers, $now);
            $this->savePaymentAccounts($id, $profile->paymentAccounts, $now);

            return $this->findByContextId($profile->contextId) ?? $profile->withId($id);
        });
    }

    /**
     * @param list<Signer> $signers
     */
    private function saveSigners(int $institutionId, array $signers, string $now): void
    {
        $existing = $this->db->table('conf_signers')->where('institution_id', $institutionId)->pluck('id')
            ->map(static fn ($v): int => (int) $v)->all();
        $kept = [];
        foreach ($signers as $seq => $signer) {
            $row = [
                'seq' => $seq,
                'signature_path' => $signer->signaturePath,
                'document_types' => self::encode(array_map(static fn ($t) => $t->value, $signer->documentTypes)),
                'updated_at' => $now,
            ];
            if ($signer->id !== null && in_array($signer->id, $existing, true)) {
                $signerId = $signer->id;
                $this->db->table('conf_signers')->where('id', $signerId)->update($row);
            } else {
                $signerId = (int) $this->db->table('conf_signers')->insertGetId(
                    $row + ['institution_id' => $institutionId, 'created_at' => $now],
                );
            }
            $kept[] = $signerId;
            $this->replaceSettings('conf_signer_settings', 'signer_id', $signerId, [
                'fullName' => $signer->fullName,
                'position' => $signer->position,
            ]);
        }
        $removed = array_diff($existing, $kept);
        if ($removed !== []) {
            $this->db->table('conf_signers')->whereIn('id', $removed)->delete();
        }
    }

    /**
     * Accounts removed from the form are deleted while nothing refers to
     * them. Once invoices exist (sprint 5+), invoices keep a snapshot and
     * removal turns into deactivation.
     *
     * @param list<PaymentAccount> $accounts
     */
    private function savePaymentAccounts(int $institutionId, array $accounts, string $now): void
    {
        $existing = $this->db->table('conf_payment_accounts')->where('institution_id', $institutionId)->pluck('id')
            ->map(static fn ($v): int => (int) $v)->all();
        $kept = [];
        foreach ($accounts as $seq => $account) {
            $row = [
                'seq' => $seq,
                'kind' => $account->kind->value,
                'label' => $account->label,
                'recipient_name' => $account->recipientName,
                'iban' => $account->iban,
                'bank_name' => $account->bankName,
                'legal_id' => $account->legalId,
                'purpose_template' => $account->purposeTemplate,
                'extra' => self::encode($account->extra),
                'is_active' => $account->isActive,
                'updated_at' => $now,
            ];
            if ($account->id !== null && in_array($account->id, $existing, true)) {
                $this->db->table('conf_payment_accounts')->where('id', $account->id)->update($row);
                $kept[] = $account->id;
            } else {
                $kept[] = (int) $this->db->table('conf_payment_accounts')->insertGetId(
                    $row + ['institution_id' => $institutionId, 'created_at' => $now],
                );
            }
        }
        $removed = array_diff($existing, $kept);
        if ($removed !== []) {
            $this->db->table('conf_payment_accounts')->whereIn('id', $removed)->delete();
        }
    }

    /**
     * @param list<int> $parentIds
     * @return array<int, array<string, array<string, string>>> parentId => field => locale => value
     */
    private function loadSettings(string $table, string $parentColumn, array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }
        $result = [];
        foreach ($this->db->table($table)->whereIn($parentColumn, $parentIds)->get() as $r) {
            $result[(int) $r->{$parentColumn}][$r->setting_name][$r->locale] = (string) $r->setting_value;
        }
        return $result;
    }

    /**
     * @param array<string, LocalizedText> $fields
     */
    private function replaceSettings(string $table, string $parentColumn, int $parentId, array $fields): void
    {
        $this->db->table($table)->where($parentColumn, $parentId)
            ->whereIn('setting_name', array_keys($fields))->delete();
        $rows = [];
        foreach ($fields as $name => $text) {
            foreach ($text->toArray() as $locale => $value) {
                $rows[] = [$parentColumn => $parentId, 'locale' => $locale, 'setting_name' => $name, 'setting_value' => $value];
            }
        }
        if ($rows !== []) {
            $this->db->table($table)->insert($rows);
        }
    }

    /** @param array<mixed> $value */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<mixed> */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }
}
