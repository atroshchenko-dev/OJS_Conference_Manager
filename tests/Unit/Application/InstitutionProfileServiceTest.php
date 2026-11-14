<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Application;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\DocumentType;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\PaymentAccountKind;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use PHPUnit\Framework\TestCase;

final class InstitutionProfileServiceTest extends TestCase
{
    private InMemoryInstitutionRepository $repo;
    private InstitutionProfileService $service;
    private ContextInfo $uk;
    private ContextInfo $en;

    protected function setUp(): void
    {
        $this->repo = new InMemoryInstitutionRepository();
        $this->service = new InstitutionProfileService($this->repo, JurisdictionRegistry::withBuiltIns());
        $this->uk = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Конференції закладу А'], 'a@example.org');
        $this->en = new ContextInfo(2, 'en', ['en'], ['en' => 'Institute B events'], 'b@example.org');
    }

    /** @return array<string, mixed> */
    private static function validUa(): array
    {
        return [
            'jurisdiction' => 'UA',
            'institutionType' => 'ippo',
            'ownership' => 'communal',
            'legalId' => '02070944',
            'name' => ['uk' => 'Інститут післядипломної освіти', 'en' => 'In-service institute', 'de' => 'ignored'],
            'extra' => ['edeboId' => '1234'],
            'signers' => [
                ['fullName' => ['uk' => 'Коваль Олена'], 'position' => ['uk' => 'Директор'], 'documentTypes' => ['invoice', 'certificate_pk', 'bogus']],
            ],
            'paymentAccounts' => [
                ['kind' => 'treasury', 'recipientName' => 'ІППО', 'iban' => 'UA118201720000031000000000001', 'purposeTemplate' => 'Оргвнесок {invoiceNumber}'],
            ],
        ];
    }

    public function testDraftUsesContextNameAndLocaleDefaultJurisdiction(): void
    {
        $uk = $this->service->getOrDraft($this->uk);
        self::assertNull($uk->id);
        self::assertSame('UA', $uk->jurisdiction);
        self::assertSame('Конференції закладу А', $uk->text('name')->get('uk'));
        self::assertSame('a@example.org', $uk->contactEmail);
        self::assertSame('generic', $this->service->getOrDraft($this->en)->jurisdiction);
    }

    public function testSavesValidProfileAndDropsUnusedLocalesAndUnknownDocumentTypes(): void
    {
        [$saved, $errors] = $this->service->save($this->uk, self::validUa());
        self::assertTrue($errors->isEmpty(), json_encode($errors->toArray()) ?: '');
        self::assertNotNull($saved);
        self::assertSame(1, $saved->id);
        self::assertSame(['en', 'uk'], $saved->text('name')->locales());
        self::assertSame([DocumentType::Invoice, DocumentType::CertificateProfessionalDevelopment], $saved->signers[0]->documentTypes);
        self::assertSame('Коваль Олена', $saved->signerFor(DocumentType::Invoice)?->fullName->get('uk'));
        self::assertNull($saved->signerFor(DocumentType::CertificateSpeaker));
        self::assertSame(PaymentAccountKind::Treasury, $saved->activePaymentAccounts()[0]->kind);
    }

    public function testReportsErrorsWithoutSaving(): void
    {
        $input = self::validUa();
        $input['legalId'] = '02070945';
        $input['name'] = ['en' => 'Only English'];
        $input['ownership'] = 'imaginary';
        $input['extra']['edeboId'] = '12a';
        $input['paymentAccounts'][0]['iban'] = 'DE89370400440532013000';
        $input['paymentAccounts'][0]['purposeTemplate'] = '{invoiceNumber} {secret}';
        $input['signers'][0]['position'] = [];

        [$saved, $errors] = $this->service->save($this->uk, $input);
        self::assertNull($saved);
        self::assertSame([], $this->repo->profiles);
        $fields = array_keys($errors->toArray());
        sort($fields);
        self::assertSame([
            'extra.edeboId',
            'legalId',
            'name',
            'ownership',
            'paymentAccounts.0.iban',
            'paymentAccounts.0.purposeTemplate',
            'signers.0.position',
        ], $fields);
        self::assertSame(['plugins.generic.conferenceManager.validation.ibanUa'], $errors->toArray()['paymentAccounts.0.iban']);
    }

    public function testGenericJurisdictionHasNoNationalRules(): void
    {
        [$saved, $errors] = $this->service->save($this->en, [
            'jurisdiction' => 'generic',
            'institutionType' => 'research_institute',
            'name' => ['en' => 'Institute B'],
            'legalId' => 'KRS 0000123456',
            'paymentAccounts' => [['kind' => 'bank', 'recipientName' => 'Institute B', 'iban' => 'DE89 3704 0044 0532 0130 00']],
        ]);
        self::assertTrue($errors->isEmpty(), json_encode($errors->toArray()) ?: '');
        self::assertSame('DE89370400440532013000', $saved?->paymentAccounts[0]->iban);

        [, $errors] = $this->service->save($this->en, [
            'jurisdiction' => 'generic',
            'name' => ['en' => 'Institute B'],
            'paymentAccounts' => [['kind' => 'treasury', 'recipientName' => 'X', 'iban' => 'DE89370400440532013000']],
        ]);
        self::assertTrue($errors->has('paymentAccounts.0.kind'));
    }

    public function testUnknownJurisdictionIsRejected(): void
    {
        [$saved, $errors] = $this->service->save($this->uk, ['jurisdiction' => 'XX', 'name' => ['uk' => 'А']]);
        self::assertNull($saved);
        self::assertTrue($errors->has('jurisdiction'));
    }

    public function testExportImportRoundTripBetweenContexts(): void
    {
        [$saved] = $this->service->save($this->uk, self::validUa());
        self::assertNotNull($saved);
        $export = $this->service->export($this->uk);
        self::assertSame(InstitutionProfileService::EXPORT_FORMAT, $export['format']);
        self::assertArrayNotHasKey('id', $export['profile']);
        self::assertArrayNotHasKey('id', $export['profile']['signers'][0]);

        $other = new ContextInfo(3, 'uk', ['uk', 'en'], [], '');
        [$imported, $errors] = $this->service->import($other, json_encode($export, JSON_THROW_ON_ERROR));
        self::assertTrue($errors->isEmpty(), json_encode($errors->toArray()) ?: '');
        self::assertSame(3, $imported?->contextId);
        self::assertNotSame($saved->id, $imported?->id);
        self::assertSame($saved->toArray(portable: true)['name'], $imported?->toArray(portable: true)['name']);
        self::assertCount(1, $imported?->paymentAccounts ?? []);
    }

    public function testImportRejectsForeignFilesAndNewerVersions(): void
    {
        [, $errors] = $this->service->import($this->uk, '{"hello":"world"}');
        self::assertSame(['import' => ['plugins.generic.conferenceManager.validation.importFormat']], $errors->toArray());

        [, $errors] = $this->service->import($this->uk, json_encode([
            'format' => InstitutionProfileService::EXPORT_FORMAT,
            'version' => 99,
            'profile' => [],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(['import' => ['plugins.generic.conferenceManager.validation.importVersion']], $errors->toArray());
    }

    public function testUpdateKeepsIdOfExistingProfile(): void
    {
        [$first] = $this->service->save($this->uk, self::validUa());
        $input = self::validUa();
        $input['name']['uk'] = 'Нова назва';
        [$second] = $this->service->save($this->uk, $input);
        self::assertSame($first?->id, $second?->id);
        self::assertSame('Нова назва', $second?->text('name')->get('uk'));
    }
}
