<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\BookPdfRenderer;
use APP\plugins\generic\conferenceManager\classes\Application\PdfRenderer;
use Mpdf\Container\SimpleContainer;
use Mpdf\File\LocalContentLoaderInterface;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * PDF through mPDF, which OJS ships, with its DejaVu fonts for Cyrillic.
 * Neither remote nor local files are read: the document is built from text
 * and data: URIs (the QR code) only, so a template edited by a manager
 * cannot pull a file of the server or of another journal into a PDF.
 */
final class MpdfRenderer implements PdfRenderer, BookPdfRenderer
{
    public function render(string $html, string $title, bool $landscape = false): string
    {
        $m = $landscape ? 10 : 15;
        return self::output($html, $title, [
            'format' => $landscape ? 'A4-L' : 'A4',
            'margin_left' => $m, 'margin_right' => $m, 'margin_top' => $m, 'margin_bottom' => $m,
        ]);
    }

    public function renderBook(string $html, string $title, string $format): string
    {
        $a5 = $format === 'A5';
        return self::output($html, $title, [
            'format' => $a5 ? 'A5' : 'A4',
            'mirrorMargins' => true,
            // Inner margin wider for the binding.
            'margin_left' => $a5 ? 20 : 25,
            'margin_right' => $a5 ? 15 : 20,
            'margin_top' => $a5 ? 15 : 20,
            'margin_bottom' => $a5 ? 18 : 22,
            'margin_footer' => $a5 ? 8 : 10,
        ]);
    }

    /** @param array<string, mixed> $layout */
    private static function output(string $html, string $title, array $layout): string
    {
        $tmp = sys_get_temp_dir() . '/conferenceManager-mpdf';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0700, true);
        }
        $mpdf = new Mpdf($layout + [
            'mode' => 'utf-8',
            'default_font' => 'dejavusans',
            'tempDir' => $tmp,
            'curlAllowUnsafeSslRequests' => false,
            'whitelistStreamWrappers' => [],
        ], new SimpleContainer(['localContentLoader' => new class () implements LocalContentLoaderInterface {
            public function load($path)
            {
                return null;
            }
        }]));
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('OJS Conference Manager');
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
