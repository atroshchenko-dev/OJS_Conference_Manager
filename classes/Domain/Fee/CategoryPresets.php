<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;

/**
 * Starting sets of participant categories by institution type (FEE-01).
 * Only generic groups: the manager renames, removes or adds categories
 * for each edition. Names are locale keys.
 */
final class CategoryPresets
{
    private const K = 'plugins.generic.conferenceManager.fee.preset.';

    /**
     * @return list<array{code: string, nameKey: string, requiresProof: bool}>
     */
    public static function for(InstitutionType $type): array
    {
        $codes = match ($type) {
            InstitutionType::University, InstitutionType::College => [
                ['own_staff', true], ['partners', false], ['other_faculty', false], ['students', true], ['foreign', false],
            ],
            InstitutionType::Ippo => [
                ['teachers_region', true], ['teachers_other', false], ['heads', false], ['students', true], ['foreign', false],
            ],
            InstitutionType::ResearchInstitute => [
                ['own_staff', true], ['researchers', false], ['young_researchers', true], ['students', true], ['foreign', false],
            ],
            InstitutionType::Man => [
                ['pupils', true], ['teachers_supervisors', false], ['guests', false],
            ],
            InstitutionType::Ngo, InstitutionType::Other => [
                ['members', true], ['general', false], ['students', true], ['foreign', false],
            ],
        };
        return array_map(static fn (array $c): array => [
            'code' => $c[0],
            'nameKey' => self::K . $c[0],
            'requiresProof' => $c[1],
        ], $codes);
    }

    /** @return list<string> every preset code, for locale checks */
    public static function allCodes(): array
    {
        $all = [];
        foreach (InstitutionType::cases() as $type) {
            foreach (self::for($type) as $c) {
                $all[$c['code']] = true;
            }
        }
        return array_keys($all);
    }
}
