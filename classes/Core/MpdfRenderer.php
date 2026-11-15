<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\PdfRenderer;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * PDF through mPDF, which OJS ships, with its DejaVu fonts for Cyrillic.
 * Remote resources are not fetched: the document is built from text only.
 */
final class MpdfRenderer implements PdfRenderer
{
    public function render(string $html, string $title): string
    {
        $tmp = sys_get_temp_dir() . '/conferenceManager-mpdf';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0700, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15,
            'tempDir' => $tmp,
            'curlAllowUnsafeSslRequests' => false,
            'whitelistStreamWrappers' => [],
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('OJS Conference Manager');
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
