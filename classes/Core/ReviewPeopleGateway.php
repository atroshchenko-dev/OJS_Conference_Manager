<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Application\ReviewPeople;
use Illuminate\Support\Facades\DB;
use PKP\author\Author;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

final class ReviewPeopleGateway implements ReviewPeople
{
    public function editors(int $submissionId, string $locale): array
    {
        $ids = StageAssignment::withSubmissionIds([$submissionId])
            ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
            ->get()->pluck('user_id')->map(static fn ($v): int => (int) $v)->unique()->values()->all();
        return array_values(array_filter(array_map(fn (int $id): ?array => $this->user($id, $locale), $ids)));
    }

    public function user(int $userId, string $locale): ?array
    {
        $user = Repo::user()->get($userId);
        if ($user === null) {
            return null;
        }
        $affiliations = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), (array) ($user->getData('affiliation') ?? []))));
        return ['userId' => $userId, 'name' => trim($user->getFullName(true, false, $locale)), 'affiliations' => array_values(array_unique($affiliations))];
    }

    public function authors(int $submissionId, string $locale): array
    {
        $publication = Repo::submission()->get($submissionId)?->getCurrentPublication();
        $out = [];
        foreach ($publication?->getData('authors') ?? [] as $author) {
            if (!$author instanceof Author) {
                continue;
            }
            $affiliations = [];
            foreach ($author->getAffiliations() as $a) {
                if ($a->getRor()) {
                    $affiliations[] = 'ROR ' . $a->getRor();
                }
                foreach ([(array) ($a->getName(null) ?? []), [(string) $a->getLocalizedName($locale)]] as $names) {
                    foreach ($names as $n) {
                        if (trim((string) $n) !== '') {
                            $affiliations[] = trim((string) $n);
                        }
                    }
                }
            }
            $out[] = ['name' => trim($author->getFullName(true, false, $locale)), 'affiliations' => array_values(array_unique($affiliations))];
        }
        return $out;
    }

    public function reviewForms(int $contextId, string $locale): array
    {
        $ids = DB::table('review_forms')->where('assoc_type', \APP\core\Application::getContextAssocType())->where('assoc_id', $contextId)
            ->where('is_active', 1)->orderBy('seq')->pluck('review_form_id')->map(static fn ($v): int => (int) $v)->all();
        if ($ids === []) {
            return [];
        }
        $titles = [];
        foreach (DB::table('review_form_settings')->whereIn('review_form_id', $ids)->where('setting_name', 'title')->get(['review_form_id', 'locale', 'setting_value']) as $r) {
            $titles[(int) $r->review_form_id][(string) $r->locale] = (string) $r->setting_value;
        }
        return array_map(static fn (int $id): array => ['id' => $id, 'title' => (string) (($titles[$id][$locale] ?? '') !== '' ? $titles[$id][$locale] : (array_values(array_filter($titles[$id] ?? []))[0] ?? '#' . $id))], $ids);
    }

    public function roundNumber(int $reviewRoundId): ?int
    {
        $round = DB::table('review_rounds')->where('review_round_id', $reviewRoundId)->value('round');
        return $round === null ? null : (int) $round;
    }
}
