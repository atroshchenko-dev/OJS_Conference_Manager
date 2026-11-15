<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Files;

use APP\plugins\generic\conferenceManager\classes\Application\ProofStore;

/**
 * Receipts on disk under a private base folder (in OJS: files_dir, which is
 * outside the web root). Keys are made by the service; anything that could
 * leave the base folder is refused.
 */
final class LocalProofStore implements ProofStore
{
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
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            return false;
        }
        if (!@copy($sourcePath, $target)) {
            return false;
        }
        @chmod($target, 0o660);
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
        if ($this->baseDir === '' || !preg_match('#^[0-9]+/[0-9]+/[0-9a-f]+-[0-9a-f]+\.(pdf|jpg|png)$#', $key)) {
            return null;
        }
        return rtrim($this->baseDir, '/') . '/' . $key;
    }
}
