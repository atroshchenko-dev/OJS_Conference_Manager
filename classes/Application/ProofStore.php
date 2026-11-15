<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Private storage for receipt files (FEE-09). Files are kept outside the
 * web root and are given out only through checked requests.
 */
interface ProofStore
{
    /** Copies the file in under the key; false when it could not be saved. */
    public function put(string $sourcePath, string $key): bool;

    /** The content, or null when the file is gone. */
    public function read(string $key): ?string;

    public function remove(string $key): void;
}
