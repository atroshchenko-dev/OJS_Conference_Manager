<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Editions with the query builder. Every query is bound to a context id.
 */
final class DbEditionRepository implements EditionRepository
{
    private const TABLE = 'conf_editions';
    private const SETTINGS = 'conf_edition_settings';

    /** Entity field => column, for the plain columns. */
    private const COLUMNS = [
        'path' => 'path',
        'status' => 'status',
        'eventType' => 'event_type',
        'submissionMode' => 'submission_mode',
        'publicationMode' => 'publication_mode',
        'externalProceedingsUrl' => 'external_proceedings_url',
        'format' => 'format',
        'timezone' => 'timezone',
        'reviewMode' => 'review_mode',
        'isbn' => 'isbn',
        'udc' => 'udc',
        'vectorProgramId' => 'vector_program_id',
        'hours' => 'hours',
        'ectsX100' => 'ects_x100',
        'issueId' => 'issue_id',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public function find(int $contextId, int $id): ?Edition
    {
        $row = $this->query($contextId)->where('id', $id)->first();
        return $row === null ? null : ($this->hydrate([$row])[0] ?? null);
    }

    public function findByPath(int $contextId, string $path): ?Edition
    {
        $row = $this->query($contextId)->where('path', strtolower($path))->first();
        return $row === null ? null : ($this->hydrate([$row])[0] ?? null);
    }

    public function list(int $contextId, array $statuses = [], int $limit = 50, int $offset = 0): array
    {
        $rows = $this->filtered($contextId, $statuses)
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->limit($limit)->offset($offset)->get()->all();
        return $this->hydrate($rows);
    }

    public function count(int $contextId, array $statuses = []): int
    {
        return $this->filtered($contextId, $statuses)->count();
    }

    public function save(Edition $edition): Edition
    {
        return $this->db->transaction(function () use ($edition): Edition {
            $data = $edition->toArray();
            $row = ['context_id' => $edition->contextId, 'updated_at' => gmdate('Y-m-d H:i:s')];
            foreach (self::COLUMNS as $field => $column) {
                $row[$column] = $data[$field];
            }
            foreach (Edition::DATE_FIELDS as $field => $column) {
                $row[$column] = DateInput::toStorage($edition->date($field));
            }

            $id = $edition->id;
            if ($id !== null && $this->query($edition->contextId)->where('id', $id)->exists()) {
                $this->query($edition->contextId)->where('id', $id)->update($row);
            } else {
                $row['created_at'] = $row['updated_at'];
                $id = (int) $this->db->table(self::TABLE)->insertGetId($row);
            }

            $this->db->table(self::SETTINGS)->where('edition_id', $id)
                ->whereIn('setting_name', Edition::LOCALIZED_FIELDS)->delete();
            $settings = [];
            foreach ($edition->texts() as $name => $text) {
                foreach ($text->toArray() as $locale => $value) {
                    $settings[] = ['edition_id' => $id, 'locale' => $locale, 'setting_name' => $name, 'setting_value' => $value];
                }
            }
            if ($settings !== []) {
                $this->db->table(self::SETTINGS)->insert($settings);
            }
            return $edition->withId($id);
        });
    }

    public function findOpenCallsPastDeadline(\DateTimeImmutable $now, int $limit = 500): array
    {
        $rows = $this->db->table(self::TABLE)
            ->where('status', EditionStatus::CfpOpen->value)
            ->whereNotNull('abstract_deadline')
            ->where('abstract_deadline', '<=', DateInput::toStorage($now))
            ->orderBy('id')->limit($limit)->get()->all();
        return $this->hydrate($rows);
    }

    public function isInUse(int $contextId, int $id): bool
    {
        if (!$this->query($contextId)->where('id', $id)->exists()) {
            return false;
        }
        $schema = $this->db->getSchemaBuilder();
        foreach (['conf_participants', 'conf_invoices', 'conf_certificates'] as $table) {
            if ($schema->hasTable($table) && $this->db->table($table)->where('edition_id', $id)->exists()) {
                return true;
            }
        }
        // Submissions are linked through the publication property
        // "conferenceEditionId" (stored by OJS in publication_settings).
        return $schema->hasTable('publication_settings')
            && $this->db->table('publication_settings')
                ->where('setting_name', 'conferenceEditionId')
                ->where('setting_value', (string) $id)
                ->exists();
    }

    public function delete(int $contextId, int $id): bool
    {
        return $this->query($contextId)->where('id', $id)->delete() > 0;
    }

    private function query(int $contextId): Builder
    {
        return $this->db->table(self::TABLE)->where('context_id', $contextId);
    }

    /**
     * @param list<EditionStatus> $statuses
     */
    private function filtered(int $contextId, array $statuses): Builder
    {
        $query = $this->query($contextId);
        if ($statuses !== []) {
            $query->whereIn('status', array_map(static fn (EditionStatus $s): string => $s->value, $statuses));
        }
        return $query;
    }

    /**
     * Rows of conf_editions as entities (also used by cross-context queries).
     *
     * @param list<object> $rows
     * @return list<Edition>
     */
    public function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (object $r): int => (int) $r->id, $rows);
        $texts = [];
        foreach ($this->db->table(self::SETTINGS)->whereIn('edition_id', $ids)->get() as $s) {
            $texts[(int) $s->edition_id][$s->setting_name][$s->locale] = (string) $s->setting_value;
        }

        $out = [];
        foreach ($rows as $r) {
            $data = [];
            foreach (self::COLUMNS as $field => $column) {
                $data[$field] = $r->{$column};
            }
            foreach (Edition::DATE_FIELDS as $field => $column) {
                // Stored in UTC; DateInput passes DateTimeImmutable through.
                $date = DateInput::fromStorage($r->{$column} === null ? null : (string) $r->{$column});
                $data[$field] = $date;
            }
            foreach (Edition::LOCALIZED_FIELDS as $field) {
                $data[$field] = $texts[(int) $r->id][$field] ?? [];
            }
            foreach (['hours', 'ectsX100'] as $int) {
                $data[$int] = $data[$int] === null ? null : (string) $data[$int];
            }
            // Stored data was valid when saved; values unknown to this
            // version fall back to defaults instead of failing the read.
            $out[] = Edition::fromArray((int) $r->context_id, $data, (int) $r->id, (string) $r->timezone, new ValidationErrors());
        }
        return $out;
    }
}
