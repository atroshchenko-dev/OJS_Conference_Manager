<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure;

use Illuminate\Database\ConnectionInterface;

/**
 * Append-only audit log (DAT-05). There is deliberately no update or
 * delete method.
 */
final class AuditLogger
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * @param array<mixed>|null $before
     * @param array<mixed>|null $after
     */
    public function log(
        ?int $contextId,
        ?int $actorUserId,
        string $entity,
        ?int $entityId,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
    ): void {
        $this->db->table('conf_audit_log')->insert([
            'context_id' => $contextId,
            'actor_user_id' => $actorUserId,
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
