<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Legacy\LegacyUrlRepository;
use Illuminate\Database\Connection;

/** LEG-02 on conf_legacy_urls. */
final class DbLegacyUrlRepository implements LegacyUrlRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public static function path(string $conference, string $schedConf, int $ocsPaperId): string
    {
        return $conference . '/' . $schedConf . '/' . $ocsPaperId;
    }

    public function all(int $contextId): array
    {
        return array_map(self::row(...), $this->db->table('conf_legacy_urls')->where('context_id', $contextId)
            ->orderBy('old_path')->get(['ocs_paper_id', 'old_path', 'submission_id'])->all());
    }

    public function find(int $contextId, int $ocsPaperId): ?array
    {
        $r = $this->db->table('conf_legacy_urls')->where('context_id', $contextId)->where('ocs_paper_id', $ocsPaperId)
            ->orderByDesc('id')->first(['ocs_paper_id', 'old_path', 'submission_id']);
        return $r === null ? null : self::row($r);
    }

    public function save(int $contextId, array $rows): array
    {
        $taken = [];
        $this->db->transaction(function () use ($contextId, $rows, &$taken): void {
            $now = gmdate('Y-m-d H:i:s');
            foreach (array_chunk($rows, 500) as $chunk) {
                $paths = array_map(static fn (array $r): string => self::path($r['conference'], $r['schedConf'], $r['ocsPaperId']), $chunk);
                $existing = [];
                foreach ($this->db->table('conf_legacy_urls')->whereIn('old_path', $paths)->get(['id', 'context_id', 'old_path'])->all() as $e) {
                    $existing[(string) $e->old_path] = [(int) $e->id, (int) $e->context_id];
                }
                foreach ($chunk as $i => $r) {
                    $path = $paths[$i];
                    $values = ['ocs_paper_id' => $r['ocsPaperId'], 'submission_id' => $r['submissionId'], 'updated_at' => $now];
                    if (isset($existing[$path])) {
                        if ($existing[$path][1] !== $contextId) {
                            $taken[] = $path;
                            continue;
                        }
                        $this->db->table('conf_legacy_urls')->where('id', $existing[$path][0])->update($values);
                        continue;
                    }
                    $this->db->table('conf_legacy_urls')->insert($values + ['context_id' => $contextId, 'old_path' => $path, 'created_at' => $now]);
                    $existing[$path] = [0, $contextId];
                }
            }
        });
        return $taken;
    }

    public function clear(int $contextId): int
    {
        return $this->db->table('conf_legacy_urls')->where('context_id', $contextId)->delete();
    }

    /** @return array{ocsPaperId: int, conference: string, schedConf: string, submissionId: ?int} */
    private static function row(object $r): array
    {
        $parts = explode('/', (string) $r->old_path);
        return [
            'ocsPaperId' => (int) $r->ocs_paper_id,
            'conference' => $parts[0],
            'schedConf' => (string) ($parts[1] ?? ''),
            'submissionId' => $r->submission_id === null ? null : (int) $r->submission_id,
        ];
    }
}
