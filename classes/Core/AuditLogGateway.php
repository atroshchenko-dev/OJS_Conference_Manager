<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use Illuminate\Support\Facades\DB;

/**
 * Reads the audit log (DAT-05) with the names of the people who acted.
 * The log itself is append-only (Infrastructure\AuditLogger).
 */
final class AuditLogGateway
{
    public const MAX_PAGE = 100;

    /**
     * @return array{items: list<array<string, mixed>>, itemsMax: int, entities: list<string>}
     */
    public function list(int $contextId, string $entity, string $action, int $count, int $offset): array
    {
        $q = DB::table('conf_audit_log as a')->leftJoin('users as u', 'u.user_id', '=', 'a.actor_user_id')->where('a.context_id', $contextId);
        if ($entity !== '') {
            $q->where('a.entity', $entity);
        }
        if ($action !== '') {
            $q->where('a.action', $action);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('a.id')->limit(max(1, min(self::MAX_PAGE, $count)))->offset(max(0, $offset))
            ->get(['a.id', 'a.created_at', 'a.actor_user_id', 'u.username', 'u.email', 'a.entity', 'a.entity_id', 'a.action', 'a.before_data', 'a.after_data', 'a.ip'])->all();
        return [
            'items' => array_map(static fn (object $r): array => [
                'id' => (int) $r->id,
                'createdAt' => DateInput::toIsoUtc(DateInput::fromStorage((string) $r->created_at)),
                'actorUserId' => $r->actor_user_id === null ? null : (int) $r->actor_user_id,
                'actor' => $r->username === null ? null : (string) $r->username,
                'entity' => (string) $r->entity,
                'entityId' => $r->entity_id === null ? null : (int) $r->entity_id,
                'action' => (string) $r->action,
                'before' => json_decode((string) ($r->before_data ?? 'null'), true),
                'after' => json_decode((string) ($r->after_data ?? 'null'), true),
                'ip' => $r->ip === null ? null : (string) $r->ip,
            ], $rows),
            'itemsMax' => $total,
            'entities' => array_map('strval', DB::table('conf_audit_log')->where('context_id', $contextId)->distinct()->orderBy('entity')->pluck('entity')->all()),
        ];
    }
}
