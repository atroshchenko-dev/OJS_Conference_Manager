<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Certificate;

use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateDocument;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateNumber;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateRules;
use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\CertificateType;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole as R;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm as F;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use PHPUnit\Framework\TestCase;

final class CertificateRulesTest extends TestCase
{
    public function testEligibility(): void
    {
        $absentee = PresentationType::from('absentee');
        self::assertSame([CertificateType::Listener, null], CertificateRules::decide(R::Listener, true, true, F::Online, null, null, false));
        self::assertSame([null, 'notAttended'], CertificateRules::decide(R::Listener, true, false, F::Online, null, null, false));
        self::assertSame([null, 'cancelled'], CertificateRules::decide(R::Organizer, false, true, F::InPerson, null, null, false));
        self::assertSame([CertificateType::Organizer, null], CertificateRules::decide(R::Chair, true, false, F::InPerson, null, null, false));
        self::assertSame([CertificateType::Reviewer, null], CertificateRules::decide(R::Reviewer, true, false, F::InPerson, null, null, false));
        self::assertSame([CertificateType::Speaker, null], CertificateRules::decide(R::Presenter, true, true, F::InPerson, null, true, false));
        self::assertSame([CertificateType::Participation, null], CertificateRules::decide(R::Coauthor, true, true, F::InPerson, null, true, false));
        self::assertSame([null, 'notAccepted'], CertificateRules::decide(R::Presenter, true, true, F::InPerson, null, false, false));
        self::assertSame([CertificateType::Participation, null], CertificateRules::decide(R::Presenter, true, false, F::Absentee, null, true, true));
        // CRT-03: the professional development document replaces the listener one; speakers keep theirs.
        self::assertSame([CertificateType::ProfessionalDevelopment, null], CertificateRules::decide(R::Listener, true, true, F::Online, null, null, false, true));
        self::assertSame([null, 'notAttended'], CertificateRules::decide(R::Listener, true, false, F::Online, null, null, false, true));
        self::assertSame([CertificateType::Speaker, null], CertificateRules::decide(R::Presenter, true, true, F::InPerson, null, true, false, true));
        self::assertSame(\APP\plugins\generic\conferenceManager\classes\Domain\Institution\DocumentType::CertificateProfessionalDevelopment, CertificateType::ProfessionalDevelopment->documentType());
        self::assertSame([null, 'notPublished'], CertificateRules::decide(R::Presenter, true, false, F::InPerson, $absentee, true, false));
        self::assertSame([null, 'notAttended'], CertificateRules::decide(R::Presenter, true, false, F::InPerson, null, true, true));
    }

    public function testNumbersAndSeries(): void
    {
        self::assertSame('CERT-2027-00042', CertificateNumber::format('CERT', 2027, 42));
        self::assertSame('NAU', CertificateNumber::series(' nau '));
        self::assertSame('CERT', CertificateNumber::series(''));
        self::assertTrue(CertificateNumber::isValidSeries('КНУ'));
        self::assertFalse(CertificateNumber::isValidSeries('bad series!'));
        self::assertFalse(CertificateNumber::isValidSeries('КНУ-Ф'), 'the hyphen separates the parts');
    }

    public function testTemplateIsCheckedAndValuesEscaped(): void
    {
        self::assertTrue(CertificateDocument::validate('<p>{recipientName}</p>')->isEmpty());
        self::assertFalse(CertificateDocument::validate('')->isEmpty());
        self::assertFalse(CertificateDocument::validate('<p onclick="x()">{recipientName}</p>')->isEmpty());
        self::assertFalse(CertificateDocument::validate('<a href="javascript:x()">{recipientName}</a>')->isEmpty());
        self::assertFalse(CertificateDocument::validate('<iframe src="x"></iframe>')->isEmpty());
        self::assertFalse(CertificateDocument::validate('<p>{nope}</p>')->isEmpty());
        self::assertTrue(CertificateDocument::validate('<p>{ects} {institutionLegalId} {partialQualifications}</p>')->isEmpty());
        self::assertStringContainsString('a<br>', CertificateDocument::render('<p>{learningOutcomes}</p>', ['learningOutcomes' => "a\nb"]));

        $html = CertificateDocument::render('<p>{recipientName}</p>{qrCode}', ['recipientName' => '<b>О\'Коннор</b>', 'verifyUrl' => 'https://x.example/v/1']);
        self::assertStringContainsString('&lt;b&gt;О&#039;Коннор&lt;/b&gt;', $html);
        self::assertStringContainsString('<img src="data:image/svg+xml;base64,', $html);
    }
}
