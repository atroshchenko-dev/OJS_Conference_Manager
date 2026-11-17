<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Privacy\EmailClaimRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class DbEmailClaimRepository implements EmailClaimRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function find(int $userId, string $email): ?array
    {
        $row = $this->db->table('conf_email_claims')->where('user_id', $userId)->where('email', $email)->first();
        if ($row === null) {
            return null;
        }
        return [
            'tokenHash' => $row->token_hash === null ? null : (string) $row->token_hash,
            'requestedAt' => $row->requested_at === null ? null : DateInput::fromStorage((string) $row->requested_at),
            'confirmedAt' => $row->confirmed_at === null ? null : DateInput::fromStorage((string) $row->confirmed_at),
        ];
    }

    public function saveRequest(int $userId, string $email, string $tokenHash, DateTimeImmutable $now): void
    {
        $values = ['token_hash' => $tokenHash, 'requested_at' => DateInput::toStorage($now)];
        $updated = $this->db->table('conf_email_claims')->where('user_id', $userId)->where('email', $email)->update($values);
        if ($updated === 0) {
            $this->db->table('conf_email_claims')->insert($values + ['user_id' => $userId, 'email' => $email]);
        }
    }

    public function confirm(int $userId, string $email, DateTimeImmutable $now): void
    {
        $this->db->table('conf_email_claims')->where('user_id', $userId)->where('email', $email)
            ->update(['token_hash' => null, 'confirmed_at' => DateInput::toStorage($now)]);
    }

    public function unlinkedCount(int $contextId, int $userId, string $email): int
    {
        return $this->db->table('conf_participants')
            ->where('context_id', $contextId)
            ->where('email', $email)
            ->where(static function (Builder $q) use ($userId): void {
                $q->whereNull('user_id')->orWhere('user_id', '!=', $userId);
            })
            ->count();
    }

    public function link(int $userId, string $email): int
    {
        return $this->db->table('conf_participants')->whereNull('user_id')->where('email', $email)->update(['user_id' => $userId]);
    }
}
