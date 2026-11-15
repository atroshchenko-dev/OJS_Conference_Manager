<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Files;

use APP\plugins\generic\conferenceManager\classes\Application\PresetSource;

/**
 * Preset JSON files from folders: `*.json`, at most 100 files of 256 KB
 * each per folder, so a wrong folder cannot slow every page down.
 */
final class JsonPresetSource implements PresetSource
{
    private const MAX_FILES = 100;
    private const MAX_BYTES = 262144;

    /** @param array<string, string> $dirs source name => folder */
    public function __construct(private readonly array $dirs)
    {
    }

    public function files(): array
    {
        $out = [];
        foreach ($this->dirs as $source => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
            sort($files);
            foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
                $size = (int) @filesize($file);
                if ($size > self::MAX_BYTES) {
                    $out[] = ['file' => $file, 'source' => $source, 'data' => null, 'error' => 'the file is larger than 256 KB'];
                    continue;
                }
                $raw = @file_get_contents($file);
                if ($raw === false) {
                    $out[] = ['file' => $file, 'source' => $source, 'data' => null, 'error' => 'the file cannot be read'];
                    continue;
                }
                try {
                    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                    $out[] = ['file' => $file, 'source' => $source, 'data' => $data, 'error' => null];
                } catch (\JsonException $e) {
                    $out[] = ['file' => $file, 'source' => $source, 'data' => null, 'error' => 'invalid JSON: ' . $e->getMessage()];
                }
            }
        }
        return $out;
    }
}
