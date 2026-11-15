<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ReminderRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;

/**
 * The dashboard of an edition (RPT-01): submissions and decisions by
 * presentation type, participants by role, invoices paid, partly paid,
 * not paid and waived. The numbers come from the same tables as the
 * registers, so they match them.
 */
final class DashboardService
{
    private const STATUS_PUBLISHED = 3;

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly RegistrationRepository $registrations,
        private readonly EditorialDirectory $editorial,
        private readonly EditionStatistics $stats,
        private readonly ReminderRepository $reminders,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function edition(ContextInfo $context, int $editionId, string $locale): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        if ($edition === null) {
            return null;
        }
        $regs = $this->registrations->registeredOfEdition($editionId);
        $ids = array_map(static fn ($r): int => $r->submissionId, $regs);
        $summaries = $this->editorial->summaries($ids, $locale);
        $sub = ['total' => 0, 'waiting' => 0, 'review' => 0, 'accepted' => 0, 'declined' => 0, 'published' => 0];
        $byType = [];
        $accepted = [];
        foreach ($regs as $r) {
            $s = $summaries[$r->submissionId] ?? null;
            if ($s === null || $s['contextId'] !== $context->id) {
                continue;
            }
            $sub['total']++;
            if ($s['status'] === EditorialDirectory::STATUS_DECLINED) {
                $sub['declined']++;
            } elseif ($s['stageId'] >= EditorialDirectory::STAGE_EDITING) {
                $sub['accepted']++;
                $accepted[] = $r->submissionId;
                $type = $r->presentationType === null ? '' : $r->presentationType->value;
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                if ($s['status'] === self::STATUS_PUBLISHED) {
                    $sub['published']++;
                }
            } elseif ($s['stageId'] === EditorialDirectory::STAGE_SUBMISSION) {
                $sub['waiting']++;
            } else {
                $sub['review']++;
            }
        }
        $types = [];
        foreach (PresentationType::cases() as $t) {
            if (isset($byType[$t->value])) {
                $types[] = ['value' => $t->value, 'label' => $this->t($t->labelKey(), [], $locale), 'count' => $byType[$t->value]];
            }
        }
        if (isset($byType[''])) {
            $types[] = ['value' => '', 'label' => $this->t('plugins.generic.conferenceManager.panel.type.none', [], $locale), 'count' => $byType['']];
        }
        if ($edition->submissionMode === SubmissionMode::AbstractsAndPapers) {
            $sub['fullText'] = count(array_filter($this->editorial->hasFullText($accepted)));
        }

        $roles = [];
        $parts = $this->stats->participations($editionId);
        foreach (ParticipantRole::cases() as $role) {
            $c = $parts[$role->value] ?? [];
            $roles[] = [
                'role' => $role->value,
                'label' => $this->t('plugins.generic.conferenceManager.registration.participantRole.' . $role->value, [], $locale),
                'active' => $c[ParticipationStatus::Active->value] ?? 0,
                'cancelled' => $c[ParticipationStatus::Cancelled->value] ?? 0,
            ];
        }

        return [
            'edition' => [
                'id' => $edition->id,
                'name' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale),
                'status' => $edition->status->value,
            ],
            'submissions' => $sub + ['byType' => $types],
            'participants' => $roles,
            'payments' => $this->payments($editionId),
            'pendingProofs' => $this->stats->pendingProofs($editionId),
            'reminders' => $this->reminders->sentCounts($editionId),
        ];
    }

    /**
     * One row per currency: paid in full, still open (with how many of them
     * are partly paid and what is still due), waived, replaced.
     *
     * @return list<array<string, mixed>>
     */
    private function payments(int $editionId): array
    {
        $out = [];
        foreach ($this->stats->invoices($editionId) as $row) {
            $cur = $row['currency'];
            $out[$cur] ??= ['paid' => 0, 'paidMinor' => 0, 'open' => 0, 'partPaid' => 0, 'dueMinor' => 0, 'receivedMinor' => 0, 'waived' => 0, 'replaced' => 0];
            $status = InvoiceStatus::tryFrom($row['status']);
            if ($row['method'] === 'waiver' || $status === InvoiceStatus::Waived) {
                $out[$cur]['waived'] += $row['count'];
            } elseif ($status === InvoiceStatus::Paid) {
                $out[$cur]['paid'] += $row['count'];
                $out[$cur]['paidMinor'] += $row['paidMinor'];
            } elseif ($status !== null && $status->isOpen()) {
                $out[$cur]['open'] += $row['count'];
                $out[$cur]['partPaid'] += $row['withPayment'];
                $out[$cur]['dueMinor'] += $row['totalMinor'] - $row['paidMinor'];
                $out[$cur]['receivedMinor'] += $row['paidMinor'];
            } else {
                $out[$cur]['replaced'] += $row['count'];
            }
        }
        $rows = [];
        foreach ($out as $cur => $p) {
            $rows[] = [
                'currency' => $cur,
                'paid' => ['count' => $p['paid'], 'amount' => (new Money($p['paidMinor'], $cur))->toArray()],
                'open' => [
                    'count' => $p['open'],
                    'partPaid' => $p['partPaid'],
                    'due' => (new Money($p['dueMinor'], $cur))->toArray(),
                    'received' => (new Money($p['receivedMinor'], $cur))->toArray(),
                ],
                'waived' => $p['waived'],
                'replaced' => $p['replaced'],
            ];
        }
        return $rows;
    }

    /** @param array<string, mixed> $params */
    private function t(string $key, array $params, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, $params, $locale);
    }
}
