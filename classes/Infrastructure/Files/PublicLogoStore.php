<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Files;

use APP\plugins\generic\conferenceManager\classes\Application\ProofStore;

/**
 * INS-04: partner logos in the public files folder of OJS, so the public
 * page can show them without a request through the plugin. Only keys made
 * by PartnerService ("{context}/{edition}-{partner}-{hash}.{ext}") are taken.
 */
final class PublicLogoStore implements ProofStore
{
    public const KEY = '#^[0-9]+/[0-9]+-[0-9]+-[0-9a-f]{12}\.(png|jpg|webp)$#';

    public function __construct(private readonly string $baseDir)
    {
    }

    public function put(string $sourcePath, string $key): bool
    {
        $target = $this->path($key);
        if ($target === null || !is_file($sourcePath)) {
            return false;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return false;
        }
        if (!@copy($sourcePath, $target)) {
            return false;
        }
        @chmod($target, 0o644);
        return true;
    }

    public function read(string $key): ?string
    {
        $path = $this->path($key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return $content === false ? null : $content;
    }

    public function remove(string $key): void
    {
        $path = $this->path($key);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private function path(string $key): ?string
    {
        if ($this->baseDir === '' || !preg_match(self::KEY, $key)) {
            return null;
        }
        return rtrim($this->baseDir, '/') . '/' . $key;
    }
}
