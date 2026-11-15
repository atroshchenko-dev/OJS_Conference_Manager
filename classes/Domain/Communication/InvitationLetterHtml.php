<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Communication;

/**
 * COM-04: the letter of invitation a participant shows to their employer
 * for a business trip or to a consulate for a visa. An A4 page in one
 * language: the letterhead of the institution, the outgoing number and
 * date, the addressee, the text and the signer. All values are escaped;
 * the text paragraphs come already translated.
 */
final class InvitationLetterHtml
{
    /**
     * @param array{institution: string, address: string, legalIdLine: string, contacts: string, numberLine: string,
     *   addresseeName: string, addresseeAffiliation: string, title: string, salutation: string,
     *   paragraphs: list<string>, signerPosition: string, signerName: string} $v
     */
    public static function render(array $v): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = implode('', array_map(static fn (string $p): string => '<p class="text">' . $e($p) . '</p>', array_values(array_filter($v['paragraphs'], static fn (string $p): bool => trim($p) !== ''))));
        $head = '<div class="head"><div class="inst">' . $e($v['institution']) . '</div>'
            . ($v['address'] !== '' ? '<div class="small">' . $e($v['address']) . '</div>' : '')
            . ($v['legalIdLine'] !== '' ? '<div class="small">' . $e($v['legalIdLine']) . '</div>' : '')
            . ($v['contacts'] !== '' ? '<div class="small">' . $e($v['contacts']) . '</div>' : '')
            . '</div>';
        $to = '<table class="meta"><tr><td class="num">' . $e($v['numberLine']) . '</td><td class="to">' . $e($v['addresseeName'])
            . ($v['addresseeAffiliation'] !== '' ? '<br>' . $e($v['addresseeAffiliation']) : '') . '</td></tr></table>';
        $sign = '<table class="sign"><tr><td>' . $e($v['signerPosition']) . '</td><td class="line"></td><td class="name">' . $e($v['signerName']) . '</td></tr></table>';
        return '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:dejavuserif;font-size:12pt;line-height:1.45;color:#000}'
            . '.head{text-align:center;border-bottom:1.5pt solid #000;padding-bottom:6pt;margin-bottom:14pt}'
            . '.inst{font-size:13pt;font-weight:bold;text-transform:uppercase}.small{font-size:9.5pt}'
            . '.meta{width:100%;margin-bottom:18pt}.meta td{vertical-align:top;font-size:11pt}.to{width:50%}'
            . 'h1{text-align:center;font-size:14pt;margin:8pt 0 12pt}.salutation{text-align:center;font-weight:bold}'
            . '.text{text-indent:1.25cm;text-align:justify;margin:0 0 8pt}'
            . '.sign{width:100%;margin-top:36pt}.sign td{vertical-align:bottom}.sign .line{border-bottom:0.5pt solid #000;width:30%}.sign .name{text-align:right}'
            . '</style></head><body>' . $head . $to
            . '<h1>' . $e($v['title']) . '</h1>'
            . ($v['salutation'] !== '' ? '<p class="salutation">' . $e($v['salutation']) . '</p>' : '')
            . $paragraphs . $sign . '</body></html>';
    }
}
