<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Application\LoadStatistics;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final class DbLoadStatistics implements LoadStatistics
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function mailsSentSince(DateTimeImmutable $since): int
    {
        return (int) $this->db->table('conf_campaign_recipients')->where('status', 'sent')
            ->where('sent_at', '>=', DateInput::toStorage($since))->count();
    }

    public function contextsWithPendingMail(): int
    {
        return (int) $this->db->table('conf_campaign_recipients as r')
            ->join('conf_campaigns as c', 'c.id', '=', 'r.campaign_id')
            ->where('r.status', 'pending')->distinct()->count('c.context_id');
    }

    public function deadlineBetween(int $contextId, DateTimeImmutable $from, DateTimeImmutable $to): ?DateTimeImmutable
    {
        $min = null;
        foreach (['abstract_deadline', 'paper_deadline'] as $column) {
            $v = $this->db->table('conf_editions')->where('context_id', $contextId)
                ->where('status', 'cfp_open')
                ->where($column, '>', DateInput::toStorage($from))->where($column, '<=', DateInput::toStorage($to))
                ->min($column);
            if ($v !== null && ($min === null || (string) $v < $min)) {
                $min = (string) $v;
            }
        }
        return DateInput::fromStorage($min);
    }
}
