<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Export;

/**
 * Writes a table to a temporary file row by row, so a long export does not
 * have to fit in memory (FEE-12).
 */
interface TableWriter
{
    /** @param list<string> $header */
    public function open(array $header): void;

    /**
     * @param list<string|null> $row
     * @param list<bool> $numeric which cells hold a decimal amount like "300.00"
     */
    public function row(array $row, array $numeric): void;

    /** Finishes the file and returns its path. */
    public function close(): string;

    public function contentType(): string;

    public function extension(): string;
}
