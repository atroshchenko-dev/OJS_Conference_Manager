<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateNumber;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final class DbCertificateRepository implements CertificateRepository
{
    private const COUNTER = 'certificate';

    public function __construct(private readonly Connection $db)
    {
    }

    public function activeTemplate(int $contextId, string $type): ?array
    {
        $r = $this->db->table('conf_certificate_templates')->where('context_id', $contextId)->where('type', $type)
            ->where('is_active', true)->orderByDesc('version')->first();
        return $r === null ? null : ['id' => (int) $r->id, 'version' => (int) $r->version, 'html' => (string) $r->body_html, 'builtIn' => (bool) $r->is_builtin];
    }

    public function saveTemplate(int $contextId, string $type, ?string $html, string $builtInHtml, DateTimeImmutable $now): int
    {
        return $this->db->transaction(function () use ($contextId, $type, $html, $builtInHtml, $now): int {
            $stamp = DateInput::toStorage($now);
            $version = (int) $this->db->table('conf_certificate_templates')->where('context_id', $contextId)->where('type', $type)->max('version');
            $this->db->table('conf_certificate_templates')->where('context_id', $contextId)->where('type', $type)
                ->update(['is_active' => false, 'updated_at' => $stamp]);
            return (int) $this->db->table('conf_certificate_templates')->insertGetId([
                'context_id' => $contextId, 'type' => $type, 'locale' => '', 'jurisdiction' => 'generic',
                'is_builtin' => $html === null, 'body_html' => $html ?? $builtInHtml, 'version' => $version + 1, 'is_active' => true,
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
        });
    }

    public function builtInTemplate(int $contextId, string $type, string $html, DateTimeImmutable $now): array
    {
        $active = $this->activeTemplate($contextId, $type);
        if ($active !== null && $active['builtIn'] && $active['html'] === $html) {
            return ['id' => $active['id'], 'version' => $active['version']];
        }
        // A built-in text changed with an update of the plugin, or first use.
        $id = $this->saveTemplate($contextId, $type, null, $html, $now);
        $r = $this->db->table('conf_certificate_templates')->where('id', $id)->first();
        return ['id' => $id, 'version' => (int) $r->version];
    }

    public function create(int $contextId, string $series, array $row, DateTimeImmutable $now): array
    {
        $year = (int) $now->format('Y');
        $key = ['context_id' => $contextId, 'kind' => self::COUNTER, 'year' => $year];
        $stamp = DateInput::toStorage($now);
        if (!$this->db->table('conf_counters')->where($key)->exists()) {
            $this->db->table('conf_counters')->insertOrIgnore($key + ['last_value' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        return $this->db->transaction(function () use ($contextId, $series, $row, $key, $year, $stamp): array {
            $counter = $this->db->table('conf_counters')->where($key)->lockForUpdate()->first();
            if ($counter === null) {
                $this->db->table('conf_counters')->insertOrIgnore($key + ['last_value' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
                $counter = $this->db->table('conf_counters')->where($key)->lockForUpdate()->first();
            }
            $seq = (int) $counter->last_value + 1;
            $this->db->table('conf_counters')->where('id', $counter->id)->update(['last_value' => $seq, 'updated_at' => $stamp]);
            $number = CertificateNumber::format($series, $year, $seq);
            $uuid = isset($row['uuid']) && is_string($row['uuid']) ? $row['uuid'] : self::uuid();
            $id = (int) $this->db->table('conf_certificates')->insertGetId([
                'context_id' => $contextId,
                'uuid' => $uuid,
                'number' => $number,
                'edition_id' => $row['edition_id'],
                'session_id' => null,
                'participant_id' => $row['participant_id'],
                'template_id' => $row['template_id'],
                'template_version' => $row['template_version'],
                'type' => $row['type'],
                'hours' => $row['hours'] ?? null,
                'ects_x100' => $row['ects_x100'] ?? null,
                'issued_at' => $stamp,
                'snapshot' => json_encode($row['snapshot'] + ['number' => $number, 'uuid' => $uuid], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            return ['id' => $id, 'number' => $number, 'uuid' => $uuid];
        }, 3);
    }

    public function setPdf(int $id, string $path, string $sha256): void
    {
        $this->db->table('conf_certificates')->where('id', $id)->update(['pdf_path' => $path, 'pdf_sha256' => $sha256, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function find(int $contextId, int $id): ?array
    {
        $r = $this->db->table('conf_certificates')->where('context_id', $contextId)->where('id', $id)->first();
        return $r === null ? null : self::hydrate($r);
    }

    public function findByUuid(string $uuid): ?array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            return null;
        }
        $r = $this->db->table('conf_certificates')->where('uuid', $uuid)->first();
        return $r === null ? null : self::hydrate($r);
    }

    public function ofEdition(int $contextId, int $editionId): array
    {
        return array_map(self::hydrate(...), $this->db->table('conf_certificates')->where('context_id', $contextId)
            ->where('edition_id', $editionId)->orderByDesc('id')->get()->all());
    }

    public function ofParticipants(array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }
        return array_map(self::hydrate(...), $this->db->table('conf_certificates')->whereIn('participant_id', $participantIds)
            ->orderByDesc('id')->get()->all());
    }

    public function revoke(int $id, string $reason, ?int $replacedById, DateTimeImmutable $now): bool
    {
        return $this->db->table('conf_certificates')->where('id', $id)->whereNull('revoked_at')->update([
            'revoked_at' => DateInput::toStorage($now),
            'revoke_reason' => $reason,
            'replaced_by_id' => $replacedById,
            'updated_at' => DateInput::toStorage($now),
        ]) > 0;
    }

    public function setReplacedBy(int $id, int $replacedById): void
    {
        $this->db->table('conf_certificates')->where('id', $id)->update(['replaced_by_id' => $replacedById, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function forExport(int $contextId, int $editionId, string $type, bool $onlyNew): array
    {
        $q = $this->db->table('conf_certificates')->where('context_id', $contextId)->where('edition_id', $editionId)
            ->where('type', $type)->whereNull('revoked_at');
        if ($onlyNew) {
            $q->whereNull('vector_exported_at');
        }
        return array_map(static fn (object $r): array => self::hydrate($r) + [
            'vectorExportedAt' => DateInput::toIsoUtc(DateInput::fromStorage($r->vector_exported_at === null ? null : (string) $r->vector_exported_at)),
        ], $q->orderBy('id')->get()->all());
    }

    public function markExported(array $ids, DateTimeImmutable $now): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->db->table('conf_certificates')->whereIn('id', $chunk)->update(['vector_exported_at' => DateInput::toStorage($now)]);
        }
    }

    public function publicList(int $contextId, string $type, int $count, int $offset): array
    {
        $q = $this->db->table('conf_certificates')->where('context_id', $contextId)->where('type', $type)->whereNull('revoked_at');
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('issued_at')->orderByDesc('id')->offset(max(0, $offset))->limit(max(1, min(200, $count)))->get()->all();
        return ['items' => array_map(static fn (object $r): array => self::hydrate($r), $rows), 'itemsMax' => $total];
    }

    /** @return array<string, mixed> */
    private static function hydrate(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'contextId' => (int) $r->context_id,
            'uuid' => (string) $r->uuid,
            'number' => (string) $r->number,
            'editionId' => (int) $r->edition_id,
            'participantId' => (int) $r->participant_id,
            'templateId' => (int) $r->template_id,
            'templateVersion' => (int) $r->template_version,
            'type' => (string) $r->type,
            'issuedAt' => DateInput::toIsoUtc(DateInput::fromStorage((string) $r->issued_at)),
            'revokedAt' => DateInput::toIsoUtc(DateInput::fromStorage($r->revoked_at === null ? null : (string) $r->revoked_at)),
            'revokeReason' => $r->revoke_reason === null ? null : (string) $r->revoke_reason,
            'replacedById' => $r->replaced_by_id === null ? null : (int) $r->replaced_by_id,
            'pdfPath' => $r->pdf_path === null ? null : (string) $r->pdf_path,
            'pdfSha256' => $r->pdf_sha256 === null ? null : (string) $r->pdf_sha256,
            'snapshot' => (array) (json_decode((string) ($r->snapshot ?? '{}'), true) ?: []),
        ];
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
