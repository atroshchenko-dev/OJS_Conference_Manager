<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AcceptanceDecision;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\BulkDecision;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\DecisionBatchRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\SpeakerRegistration;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * Bulk decisions on the abstracts of an edition (DEC-03, DEC-04): the
 * editor picks abstracts in the submission stage and one decision; each
 * submission is decided by its own job in the OJS queue, so every author
 * gets their own letter and a failure stops only that one item.
 */
final class BulkDecisionService
{
    private const K = 'plugins.generic.conferenceManager.decision.error.';

    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public function __construct(
        private readonly DecisionBatchRepository $batches,
        private readonly RegistrationRepository $registrations,
        private readonly EditionRepository $editions,
        private readonly EditorialDirectory $editorial,
        private readonly DecisionRecorder $recorder,
        private readonly DecisionQueue $queue,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
    ) {
    }

    /**
     * Abstracts of the edition waiting for a decision in the submission
     * stage. $editorId limits the list to the submissions that editor may
     * decide on (a section editor); null means all (a manager).
     *
     * @param array{search?: string, presentationType?: string, section?: string} $filters
     * @return list<array<string, mixed>>|null null when there is no such edition
     */
    public function candidates(ContextInfo $context, int $editionId, ?int $editorId, array $filters, string $locale): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $registrations = [];
        foreach ($this->registrations->registeredOfEdition($editionId) as $r) {
            $registrations[$r->submissionId] = $r;
        }
        $ids = array_keys($registrations);
        if ($editorId !== null) {
            $ids = $this->editorial->decidableBy($editorId, $ids);
        }
        $summaries = $this->editorial->summaries($ids, $locale);
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $type = PresentationType::tryFrom((string) ($filters['presentationType'] ?? ''));
        $section = trim((string) ($filters['section'] ?? ''));
        $out = [];
        foreach ($ids as $id) {
            $s = $summaries[$id] ?? null;
            $r = $registrations[$id];
            if ($s === null || $s['contextId'] !== $context->id || $s['stageId'] !== EditorialDirectory::STAGE_SUBMISSION || $s['status'] !== EditorialDirectory::STATUS_QUEUED) {
                continue;
            }
            if ($type !== null && $r->presentationType !== $type) {
                continue;
            }
            if ($section !== '' && $s['section'] !== $section) {
                continue;
            }
            if ($search !== '' && !str_contains(mb_strtolower($s['title'] . ' ' . $s['authors'] . ' ' . $id), $search)) {
                continue;
            }
            $out[] = $this->presentCandidate($id, $s, $r, $locale);
        }
        return $out;
    }

    /**
     * @param array{title: string, section: string, authors: string} $s
     * @return array<string, mixed>
     */
    private function presentCandidate(int $id, array $s, SpeakerRegistration $r, string $locale): array
    {
        $type = $r->presentationType;
        return [
            'submissionId' => $id,
            'title' => $s['title'],
            'section' => $s['section'],
            'authors' => $s['authors'],
            'presentationType' => $type?->value,
            'presentationTypeLabel' => $type === null ? null : $this->t($type->labelKey(), $locale),
            'presenterMinor' => $r->presenterMinor,
        ];
    }

    /**
     * The decisions the bulk mode offers.
     *
     * @return list<array{value: int, label: string, isDecline: bool}>
     */
    public function decisionOptions(string $locale): array
    {
        return array_map(fn (int $code): array => [
            'value' => $code,
            'label' => $this->t(
                $code === BulkDecision::DECLINE ? 'plugins.generic.conferenceManager.decision.decline' : AcceptanceDecision::from($code)->labelKey(),
                $locale,
            ),
            'isDecline' => $code === BulkDecision::DECLINE,
        ], BulkDecision::codes());
    }

    /**
     * Starts a run: checks every submission and puts one job per
     * submission in the queue.
     *
     * @param list<int> $submissionIds
     * @return ValidationErrors|array{batchId: int}|null null when there is no such edition
     */
    public function start(ContextInfo $context, int $editionId, int $decision, array $submissionIds, int $editorId, bool $isManager, DateTimeImmutable $now): ValidationErrors|array|null
    {
        $candidates = $this->candidates($context, $editionId, $isManager ? null : $editorId, [], $context->primaryLocale);
        if ($candidates === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if (!BulkDecision::isAllowed($decision)) {
            $errors->add('decision', self::K . 'decision');
        }
        $ids = array_values(array_unique(array_map('intval', $submissionIds)));
        if ($ids === []) {
            $errors->add('submissionIds', self::K . 'none');
        } elseif (count($ids) > BulkDecision::MAX_ITEMS) {
            $errors->add('submissionIds', self::K . 'tooMany');
        }
        $allowed = array_column($candidates, 'submissionId');
        if (array_diff($ids, $allowed) !== []) {
            $errors->add('submissionIds', self::K . 'notCandidate');
        }
        if (!$errors->isEmpty()) {
            return $errors;
        }
        $batchId = $this->batches->create($context->id, $editionId, $decision, $editorId, $ids, $now);
        $this->audit?->log($context->id, $editorId, 'decisionBatch', $batchId, 'created', null, ['decision' => $decision, 'count' => count($ids)]);
        foreach ($ids as $id) {
            $this->queue->push($context->id, $batchId, $id);
        }
        return ['batchId' => $batchId];
    }

    /**
     * One job: records the decision for one submission. Running the same
     * job twice does nothing the second time.
     */
    public function runItem(int $contextId, int $batchId, int $submissionId, DateTimeImmutable $now): void
    {
        $batch = $this->batches->find($contextId, $batchId);
        if ($batch === null || !$this->batches->claim($batchId, $submissionId, $now)) {
            return;
        }
        try {
            $error = $this->recorder->record($contextId, $submissionId, $batch['decision'], $batch['editorId']);
        } catch (\Throwable $e) {
            error_log('conferenceManager: bulk decision ' . $batchId . '/' . $submissionId . ': ' . $e->getMessage());
            $error = mb_substr($e->getMessage(), 0, 250);
        }
        $this->batches->finish($batchId, $submissionId, $error, $now);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(ContextInfo $context, int $batchId, string $locale, ?int $onlyEditorId = null): ?array
    {
        $batch = $this->batches->find($context->id, $batchId);
        // A section editor sees only the batches they started (security audit 0.33).
        if ($batch === null || ($onlyEditorId !== null && $batch['editorId'] !== $onlyEditorId)) {
            return null;
        }
        $ids = array_column($batch['items'], 'submissionId');
        $summaries = $this->editorial->summaries($ids, $locale);
        $counts = [self::PENDING => 0, self::RUNNING => 0, self::DONE => 0, self::FAILED => 0];
        $items = [];
        foreach ($batch['items'] as $item) {
            $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
            $items[] = $item + [
                'title' => $summaries[$item['submissionId']]['title'] ?? '',
                'statusLabel' => $this->t('plugins.generic.conferenceManager.decision.bulk.status.' . $item['status'], $locale),
            ];
        }
        return [
            'id' => $batch['id'],
            'editionId' => $batch['editionId'],
            'decision' => $batch['decision'],
            'decisionLabel' => $this->t($batch['decision'] === BulkDecision::DECLINE
                ? 'plugins.generic.conferenceManager.decision.decline'
                : (AcceptanceDecision::tryFrom($batch['decision'])?->labelKey() ?? ''), $locale),
            'createdAt' => $batch['createdAt'],
            'total' => count($items),
            'counts' => $counts,
            'finished' => $counts[self::PENDING] === 0 && $counts[self::RUNNING] === 0,
            'items' => $items,
        ];
    }

    /** @return list<array<string, mixed>>|null the last runs of the edition */
    public function runs(ContextInfo $context, int $editionId, string $locale): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        return array_values(array_filter(array_map(
            fn (int $id): ?array => $this->status($context, $id, $locale),
            $this->batches->ofEdition($context->id, $editionId, 10),
        )));
    }

    private function t(string $key, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, [], $locale);
    }
}
