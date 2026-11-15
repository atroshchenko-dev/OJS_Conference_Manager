<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

interface BookPdfRenderer
{
    /**
     * @param string $format A4 or A5; pages have mirrored margins for printing on both sides
     * @return string the PDF bytes
     */
    public function renderBook(string $html, string $title, string $format): string;
}
