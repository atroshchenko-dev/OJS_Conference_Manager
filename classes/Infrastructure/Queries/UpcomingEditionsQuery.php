<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Queries;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * "Upcoming events" across all contexts of the site (EDN-08, DST-03).
 * The only query of the plugin that is not bound to one context: it reads
 * public data of enabled journals where the plugin is enabled.
 */
final class UpcomingEditionsQuery
{
    public const STATUSES = [EditionStatus::CfpOpen, EditionStatus::Published];

    public function __construct(private readonly Connection $db, private readonly string $pluginName)
    {
    }

    /**
     * @param array{contextId?: ?int, eventType?: ?string, format?: ?string, q?: ?string} $filters
     * @return array{items: list<Edition>, itemsMax: int}
     */
    public function find(array $filters, int $limit = 50, int $offset = 0): array
    {
        $query = $this->base($filters);
        $total = (clone $query)->count();
        $rows = $query->select('e.*')
            ->orderBy('e.starts_at')->orderBy('e.id')
            ->limit(max(1, min(100, $limit)))->offset(max(0, $offset))
            ->get()->all();
        return ['items' => (new DbEditionRepository($this->db))->hydrate($rows), 'itemsMax' => $total];
    }

    /**
     * Contexts that have at least one listed edition, for the filter.
     *
     * @return list<int>
     */
    public function contextIds(): array
    {
        return $this->base([])->distinct()->orderBy('e.context_id')->pluck('e.context_id')
            ->map(static fn ($v): int => (int) $v)->all();
    }

    /**
     * @param array{contextId?: ?int, eventType?: ?string, format?: ?string, q?: ?string} $filters
     */
    private function base(array $filters): Builder
    {
        $query = $this->db->table('conf_editions as e')
            ->join('journals as j', 'j.journal_id', '=', 'e.context_id')
            ->where('j.enabled', 1)
            ->whereIn('e.status', array_map(static fn (EditionStatus $s): string => $s->value, self::STATUSES))
            ->whereExists(function (Builder $q): void {
                $q->selectRaw('1')->from('plugin_settings as ps')
                    ->whereColumn('ps.context_id', 'e.context_id')
                    ->where('ps.plugin_name', $this->pluginName)
                    ->where('ps.setting_name', 'enabled')
                    ->where('ps.setting_value', '1');
            });
        if (!empty($filters['contextId'])) {
            $query->where('e.context_id', (int) $filters['contextId']);
        }
        if (!empty($filters['eventType'])) {
            $query->where('e.event_type', (string) $filters['eventType']);
        }
        if (!empty($filters['format'])) {
            $query->where('e.format', (string) $filters['format']);
        }
        $text = trim((string) ($filters['q'] ?? ''));
        if ($text !== '') {
            // Name or venue in any locale; the venue usually holds the city.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($text)) . '%';
            $query->whereExists(function (Builder $q) use ($like): void {
                $q->selectRaw('1')->from('conf_edition_settings as s')
                    ->whereColumn('s.edition_id', 'e.id')
                    ->whereIn('s.setting_name', ['name', 'venue'])
                    ->whereRaw('LOWER(s.setting_value) LIKE ?', [$like]);
            });
        }
        return $query;
    }
}
