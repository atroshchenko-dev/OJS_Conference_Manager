<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

interface PdfRenderer
{
    /** @return string the PDF bytes; A4 landscape when $landscape */
    public function render(string $html, string $title, bool $landscape = false): string;
}
