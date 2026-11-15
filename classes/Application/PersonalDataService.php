<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Privacy\PersonalDataRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * Personal data of participants (DAT-02, DAT-03, DAT-04, DAT-06):
 * retention periods with automatic removal, an export of everything about a
 * person, anonymisation that keeps invoices and issued documents, and the
 * merge of OJS accounts.
 */
final class PersonalDataService
{
    private const SETTING = 'retention';
    private const K = 'plugins.generic.conferenceManager.privacy.';
    /** Upper bound of a period: 10 years. */
    public const MAX_MONTHS = 120;
    /** Records handled by one run of the scheduled task. */
    public const BATCH = 200;

    public function __construct(
        private readonly PersonalDataRepository $data,
        private readonly ?ProofStore $proofs,
        private readonly ?SettingsStore $settings,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
    ) {
    }

    /**
     * Months after which receipt scans are removed and participants of an
     * ended edition are anonymised; 0 keeps them. Both are 0 by default:
     * the period is the institution's decision (accounting rules).
     *
     * @return array{proofsMonths: int, participantsMonths: int}
     */
    public function retention(ContextInfo $context): array
    {
        $v = $this->settings?->get($context->id, self::SETTING);
        $v = is_array($v) ? $v : [];
        $int = static fn (mixed $x): int => is_numeric($x) && (int) $x >= 0 && (int) $x <= self::MAX_MONTHS ? (int) $x : 0;
        return ['proofsMonths' => $int($v['proofsMonths'] ?? 0), 'participantsMonths' => $int($v['participantsMonths'] ?? 0)];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveRetention(ContextInfo $context, array $input, ?int $actorId): ValidationErrors
    {
        $errors = new ValidationErrors();
        $out = [];
        foreach (['proofsMonths', 'participantsMonths'] as $key) {
            $v = $input[$key] ?? 0;
            if (!is_numeric($v) || (int) $v != $v || (int) $v < 0 || (int) $v > self::MAX_MONTHS) {
                $errors->add($key, self::K . 'error.months');
                continue;
            }
            $out[$key] = (int) $v;
        }
        if ($errors->isEmpty()) {
            $before = $this->retention($context);
            $this->settings?->set($context->id, self::SETTING, $out);
            $this->audit?->log($context->id, $actorId, 'retention', null, 'update', $before, $out);
        }
        return $errors;
    }

    /**
     * DAT-02: one run of the scheduled task for a journal.
     *
     * @return array{proofs: int, participants: int}
     */
    public function runRetention(ContextInfo $context, DateTimeImmutable $now): array
    {
        $r = $this->retention($context);
        $done = ['proofs' => 0, 'participants' => 0];
        if ($r['proofsMonths'] > 0 && $this->proofs !== null) {
            foreach ($this->data->expiredProofs($context->id, $now->modify('-' . $r['proofsMonths'] . ' months'), self::BATCH) as $p) {
                $this->proofs->remove($p['key']);
                $this->data->markProofPurged($p['id'], $now);
                ++$done['proofs'];
            }
        }
        if ($r['participantsMonths'] > 0) {
            foreach ($this->data->participantsOfEndedEditions($context->id, $now->modify('-' . $r['participantsMonths'] . ' months'), self::BATCH) as $id) {
                $done['participants'] += $this->data->anonymize($context->id, $id, $this->placeholder($context), $now) ? 1 : 0;
            }
        }
        if ($done['proofs'] + $done['participants'] > 0) {
            $this->audit?->log($context->id, null, 'retention', null, 'run', null, $done);
        }
        return $done;
    }

    /**
     * DAT-03: everything the plugin keeps about the person in this journal.
     * The person asks for their own data; a manager by an email address.
     *
     * @return array<string, mixed>
     */
    public function export(ContextInfo $context, ?int $userId, string $email, ?int $actorId, DateTimeImmutable $now): array
    {
        $ids = $this->data->participantsOf($context->id, $userId, $email);
        $this->audit?->log($context->id, $actorId, 'personal_data', null, 'export', null, ['participants' => $ids]);
        return [
            'exportedAt' => $now->format(DATE_ATOM),
            'context' => $context->id,
            'person' => ['userId' => $userId, 'email' => mb_strtolower(trim($email))],
            'participantIds' => $ids,
            'data' => $this->data->export($ids),
        ];
    }

    /**
     * DAT-04: anonymises the person's participant records in this journal.
     * Invoices and issued documents keep their copies of the data.
     *
     * @param list<int>|null $participantIds null: all records of the email
     * @return int records anonymised
     */
    public function anonymize(ContextInfo $context, ?array $participantIds, string $email, ?int $actorId, DateTimeImmutable $now): int
    {
        $ids = $participantIds ?? $this->data->participantsOf($context->id, null, $email);
        $done = 0;
        foreach ($ids as $id) {
            if ($this->data->anonymize($context->id, $id, $this->placeholder($context), $now)) {
                ++$done;
                $this->audit?->log($context->id, $actorId, 'participant', $id, 'anonymized', null, null);
            }
        }
        return $done;
    }

    /**
     * DAT-06: OJS merges two accounts; the plugin's records follow.
     *
     * @return array<string, int>
     */
    public function mergeUsers(int $oldUserId, int $newUserId): array
    {
        $out = $this->data->mergeUsers($oldUserId, $newUserId);
        $this->audit?->log(null, null, 'user', $newUserId, 'merged', ['userId' => $oldUserId], array_filter($out));
        return $out;
    }

    private function placeholder(ContextInfo $context): string
    {
        return $this->translate === null ? 'Anonymized' : ($this->translate)(self::K . 'anonymizedName', [], $context->primaryLocale);
    }
}
