<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeCategory;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\FeeRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Tariff;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRule;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\Room;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * EDN-09: what a copied edition takes from its source besides its own
 * fields: participant categories with tariffs, payment rules, rooms of the
 * program, review and abstract book settings. Tariff validity dates (an
 * early-bird price) move by as many days as the event itself moved.
 * Participants, invoices, submissions and the program's sessions are never
 * copied: they belong to one event.
 */
final class EditionCopyService
{
    public const PARTS = ['fees', 'paymentRules', 'rooms', 'settings'];
    /** Settings keyed by edition id. */
    private const EDITION_SETTINGS = [ReviewCommitteeService::SETTING, AbstractBookService::SETTING];

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly FeeRepository $fees,
        private readonly PaymentRepository $payments,
        private readonly ?ProgramRepository $program = null,
        private readonly ?SettingsStore $settings = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Copies the chosen parts from $sourceId into the new $targetId of the
     * same journal.
     *
     * @param list<string> $parts of self::PARTS
     * @return array{categories: int, tariffs: int, paymentRules: int, rooms: int, settings: int, shiftDays: int}|null
     */
    public function copyParts(ContextInfo $context, int $sourceId, int $targetId, array $parts, ?int $actorUserId = null): ?array
    {
        $source = $this->editions->find($context->id, $sourceId);
        $target = $this->editions->find($context->id, $targetId);
        if ($source === null || $target === null || $sourceId === $targetId) {
            return null;
        }
        $parts = array_values(array_intersect(self::PARTS, $parts));
        $shift = self::shiftDays($source, $target);
        $out = ['categories' => 0, 'tariffs' => 0, 'paymentRules' => 0, 'rooms' => 0, 'settings' => 0, 'shiftDays' => $shift];

        /** @var array<int, int> old category id => new */
        $categoryMap = [];
        if (in_array('fees', $parts, true) && $this->fees->categories($targetId) === []) {
            foreach ($this->fees->categories($sourceId) as $c) {
                $saved = $this->fees->saveCategory(new FeeCategory(null, $targetId, $c->code, $c->name, $c->requiresProof, $c->sortOrder));
                $categoryMap[(int) $c->id] = (int) $saved->id;
                $out['categories']++;
            }
            foreach ($this->fees->tariffs($sourceId) as $t) {
                $category = $categoryMap[$t->categoryId] ?? null;
                if ($category === null) {
                    continue;
                }
                $this->fees->saveTariff(new Tariff(
                    null,
                    $category,
                    $t->material,
                    $t->pricing,
                    $t->amount,
                    $t->presentationType,
                    $t->minPages,
                    $t->maxPages,
                    self::shift($t->validFrom, $shift),
                    self::shift($t->validTo, $shift),
                ));
                $out['tariffs']++;
            }
        }

        if (in_array('paymentRules', $parts, true) && $this->payments->rules($targetId) === []) {
            foreach ($this->payments->rules($sourceId) as $r) {
                // A rule of a category exists only when the categories came along.
                if ($r->categoryId !== null && !isset($categoryMap[$r->categoryId])) {
                    continue;
                }
                $this->payments->saveRule(new PaymentRule(
                    null,
                    $targetId,
                    $r->categoryId === null ? null : $categoryMap[$r->categoryId],
                    $r->mode,
                    $r->paymentAccountId,
                    $r->externalUrl,
                    $r->instructions,
                ));
                $out['paymentRules']++;
            }
        }

        if (in_array('rooms', $parts, true) && $this->program !== null && $this->program->rooms($targetId) === []) {
            foreach ($this->program->rooms($sourceId) as $room) {
                $this->program->saveRoom(new Room(null, $targetId, $room->name, $room->capacity, $room->onlineUrl));
                $out['rooms']++;
            }
        }

        if (in_array('settings', $parts, true) && $this->settings !== null) {
            foreach (self::EDITION_SETTINGS as $name) {
                $all = $this->settings->get($context->id, $name);
                if (is_array($all) && is_array($all[$sourceId] ?? null) && !isset($all[$targetId])) {
                    $all[$targetId] = $all[$sourceId];
                    $this->settings->set($context->id, $name, $all);
                    $out['settings']++;
                }
            }
        }

        $this->audit?->log($context->id, $actorUserId, 'edition', $targetId, 'copy_parts', null, ['from' => $sourceId] + $out);
        return $out;
    }

    /** Whole days between the starts of the two events, 0 when either has none. */
    public static function shiftDays(Edition $source, Edition $target): int
    {
        $from = $source->date('startsAt');
        $to = $target->date('startsAt');
        if ($from === null || $to === null) {
            return 0;
        }
        $tz = new \DateTimeZone($target->timezone);
        $a = new DateTimeImmutable($from->setTimezone($tz)->format('Y-m-d'), $tz);
        $b = new DateTimeImmutable($to->setTimezone($tz)->format('Y-m-d'), $tz);
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    private static function shift(?DateTimeImmutable $at, int $days): ?DateTimeImmutable
    {
        return $at === null || $days === 0 ? $at : $at->modify(($days > 0 ? '+' : '') . $days . ' days');
    }
}
