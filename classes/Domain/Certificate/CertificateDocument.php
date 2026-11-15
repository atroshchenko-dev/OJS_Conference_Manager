<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Certificate;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\TextTemplate;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

/**
 * The HTML of a certificate (CRT-01, CRT-02): a template with {variables}
 * that a context edits without code. Values are escaped; {qrCode} is the
 * QR image of {verifyUrl}. The certificate keeps the template and the
 * values it was issued with (the snapshot), so the PDF is made again
 * from the same data.
 */
final class CertificateDocument
{
    public const MAX_LENGTH = 100000;

    public const VARIABLES = [
        'institutionName', 'institutionShortName', 'recipientName', 'recipientAffiliation',
        'certificateTitle', 'roleText', 'conferenceName', 'eventDates', 'venue', 'eventFormat',
        'paperTitle', 'presentationType', 'hours', 'number', 'issueDate',
        'verifyUrl', 'qrCode', 'signerName', 'signerPosition', 'contextName',
        'eventType', 'ects', 'learningOutcomes', 'partialQualifications', 'institutionLegalId', 'programSessions',
    ];

    /** Kept with their line breaks. */
    private const MULTILINE = ['learningOutcomes', 'partialQualifications', 'programSessions'];

    /** Inserted as HTML, not as text. */
    private const RAW = ['qrCode'];

    public static function validate(string $html): ValidationErrors
    {
        $errors = new ValidationErrors();
        if (trim($html) === '') {
            $errors->add('html', 'plugins.generic.conferenceManager.certificate.template.error.empty');
        } elseif (strlen($html) > self::MAX_LENGTH) {
            $errors->add('html', 'plugins.generic.conferenceManager.certificate.template.error.tooLong');
        } elseif ((new TextTemplate($html))->unknownVariables(self::VARIABLES) !== []) {
            $errors->add('html', 'plugins.generic.conferenceManager.certificate.template.error.variables');
        } elseif (preg_match('#<\s*(script|iframe|object|embed|link|meta)\b|\son[a-z]+\s*=|javascript:#i', $html)) {
            $errors->add('html', 'plugins.generic.conferenceManager.certificate.template.error.unsafe');
        }
        return $errors;
    }

    /**
     * @param array<string, string> $values text values by variable name
     */
    public static function render(string $html, array $values): string
    {
        $out = [];
        foreach (self::VARIABLES as $name) {
            $value = (string) ($values[$name] ?? '');
            $out[$name] = in_array($name, self::RAW, true) ? '' : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (in_array($name, self::MULTILINE, true)) {
                $out[$name] = nl2br(trim($out[$name]), false);
            }
        }
        $url = (string) ($values['verifyUrl'] ?? '');
        $out['qrCode'] = $url === '' ? '' : QrCode::encode($url)->toImgTag('QR', '28mm');
        return (new TextTemplate($html))->render($out);
    }
}
