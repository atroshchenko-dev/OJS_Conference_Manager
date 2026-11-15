<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Preset\Preset;

/**
 * Every valid preset, and the files that could not be used with the
 * reason (shown on the site overview, DST-07). A site file cannot take
 * the code of a package preset: the package one stays.
 */
final class PresetLibrary
{
    /** @var ?list<Preset> */
    private ?array $presets = null;

    /** @var list<array{file: string, errors: list<string>}> */
    private array $problems = [];

    public function __construct(private readonly PresetSource $source)
    {
    }

    /** @return list<Preset> package presets first, then site ones, each by code */
    public function all(): array
    {
        if ($this->presets !== null) {
            return $this->presets;
        }
        $byCode = [];
        $files = $this->source->files();
        usort($files, static fn (array $a, array $b): int => [$a['source'] !== 'package', $a['file']] <=> [$b['source'] !== 'package', $b['file']]);
        foreach ($files as $f) {
            if ($f['error'] !== null) {
                $this->problems[] = ['file' => basename($f['file']), 'errors' => [$f['error']]];
                continue;
            }
            [$preset, $errors] = Preset::parse($f['data'], $f['source']);
            if ($preset !== null && isset($byCode[$preset->code])) {
                $errors = ["code \"{$preset->code}\" is already used by " . basename($byCode[$preset->code][1])];
                $preset = null;
            }
            if ($preset === null) {
                $this->problems[] = ['file' => basename($f['file']), 'errors' => $errors];
                continue;
            }
            $byCode[$preset->code] = [$preset, $f['file']];
        }
        return $this->presets = array_values(array_map(static fn (array $p): Preset => $p[0], $byCode));
    }

    /** @return list<array{file: string, errors: list<string>}> */
    public function problems(): array
    {
        $this->all();
        return $this->problems;
    }

    public function find(string $code): ?Preset
    {
        foreach ($this->all() as $preset) {
            if ($preset->code === $code) {
                return $preset;
            }
        }
        return null;
    }

    /** @return list<Preset> the presets for an institution type and jurisdiction */
    public function suitable(?InstitutionType $type, string $jurisdiction): array
    {
        return array_values(array_filter($this->all(), static fn (Preset $p): bool => $p->suits($type, $jurisdiction)));
    }
}
