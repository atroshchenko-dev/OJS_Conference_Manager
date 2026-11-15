<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Application\PromoService;
use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPromoRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbRegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbWaitlistRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition\EditionTest;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/** REG-11 promo codes and REG-12 the waiting list on SQLite. */
final class PromoWaitlistFlowTest extends TestCase
{
    private Connection $db;
    private ContextInfo $ctx;
    private ParticipantService $participants;
    private InvoiceService $invoices;
    private PromoService $promos;
    private FakeMailer $mailer;
    private DateTimeImmutable $now;
    private int $editionId;
    /** @var array<string, int> */
    private array $cats = [];

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->db->table('users')->insert(array_fill(0, 8, ['note' => 'u']));
        $this->ctx = new ContextInfo(1, 'uk', ['uk', 'en'], ['uk' => 'Журнал']);
        $registry = JurisdictionRegistry::withBuiltIns();
        $profiles = new InstitutionProfileService(new DbInstitutionRepository($this->db), $registry);
        [$profile] = $profiles->save($this->ctx, [
            'jurisdiction' => 'UA', 'institutionType' => 'ippo', 'ownership' => 'communal', 'legalId' => '02070944',
            'name' => ['uk' => 'Інститут'], 'address' => ['uk' => 'м. Київ'],
            'paymentAccounts' => [['kind' => 'bank', 'recipientName' => 'ІППО', 'iban' => 'UA213223130000026007233566001', 'bankName' => 'Банк']],
        ]);
        $bankId = (int) ($profile?->activePaymentAccounts()[0]->id ?? 0);
        $translate = static fn (string $key, array $params, string $locale): string => $key . ($params === [] ? '' : ':' . implode(',', array_map('strval', $params)));
        $this->invoices = new InvoiceService(
            new DbPaymentRepository($this->db),
            new DbFeeRepository($this->db),
            new DbEditionRepository($this->db),
            new DbParticipantRepository($this->db),
            new DbRegistrationRepository($this->db),
            static fn (ContextInfo $c) => $profiles->getOrDraft($c),
            new FakeInvoiceNotifier(),
            new AuditLogger($this->db),
            $translate,
            $registry,
            null,
            new DbPromoRepository($this->db),
        );
        $this->mailer = new FakeMailer();
        $this->participants = new ParticipantService(
            new DbParticipantRepository($this->db),
            new DbEditionRepository($this->db),
            new DbFeeRepository($this->db),
            new FakeSubmissions(),
            new FakeGroups(),
            new AuditLogger($this->db),
            static fn (string $k): string => $k,
            $this->invoices,
            $this->mailer,
            new DbPromoRepository($this->db),
            new DbWaitlistRepository($this->db),
        );
        $this->promos = new PromoService(new DbPromoRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        $this->now = new DateTimeImmutable('2027-02-01T10:00:00Z');

        [$e, $errors] = (new EditionService(new DbEditionRepository($this->db), $registry))->create($this->ctx, EditionTest::validInput([
            'path' => 'conf', 'status' => 'cfp_open', 'registrationClosesAt' => '2027-05-10',
        ]));
        self::assertSame([], $errors->toArray());
        $this->editionId = (int) $e?->id;
        $fees = new FeeService(new DbFeeRepository($this->db), new DbEditionRepository($this->db), new AuditLogger($this->db));
        foreach ($fees->applyPreset($this->ctx, $this->editionId, InstitutionType::University, static fn (string $k, string $l): string => $k) ?? [] as $c) {
            $this->cats[$c->code] = (int) $c->id;
        }
        $this->invoices->saveRule($this->ctx, $this->editionId, null, ['mode' => 'bank_transfer', 'paymentAccountId' => $bankId]);
        $fees->saveTariff($this->ctx, $this->editionId, null, ['categoryId' => $this->cats['other_faculty'], 'material' => 'listener', 'pricing' => 'flat', 'amount' => '200', 'currency' => 'UAH']);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{0: ?array<string, mixed>, 1: array<string, list<string>>}
     */
    private function register(int $userId, string $form = 'in_person', array $extra = []): array
    {
        [$r, $e] = $this->participants->registerListener($this->ctx, $this->editionId, [
            'userId' => $userId, 'email' => "u{$userId}@example.org",
            'givenName' => ['uk' => 'Слухач'], 'familyName' => ['uk' => (string) $userId],
            'affiliation' => null, 'country' => 'UA', 'orcid' => null, 'position' => null,
        ], ['categoryId' => $this->cats['other_faculty'], 'participationForm' => $form, 'consent' => true] + $extra, (new ConsentText('Згода'))->version, $this->now);
        return [$r, $e->toArray()];
    }

    private function invoiceOf(int $participationId): ?\APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice
    {
        return $this->invoices->currentFor($participationId);
    }

    public function testPromoCodes(): void
    {
        [$code, $e] = $this->promos->save($this->ctx, $this->editionId, null, ['code' => 'early', 'percent' => 25, 'maxUses' => 1, 'validUntil' => '2027-02-01'], 1) ?? [null, null];
        self::assertSame([], $e?->toArray());
        [, $e] = $this->promos->save($this->ctx, $this->editionId, null, ['code' => 'EARLY', 'percent' => 10], 1) ?? [null, null];
        self::assertTrue($e?->has('code'), 'duplicate');
        $this->promos->save($this->ctx, $this->editionId, null, ['code' => 'FREE', 'percent' => 100], 1);
        self::assertNull($this->promos->ofEdition(new ContextInfo(2, 'uk', ['uk']), $this->editionId));

        // Unknown and valid codes at registration.
        [$r, $e] = $this->register(1, 'online', ['promoCode' => 'nope']);
        self::assertNull($r);
        self::assertSame(['plugins.generic.conferenceManager.promo.error.unknown'], $e['promoCode']);
        [$r] = $this->register(1, 'online', ['promoCode' => ' early ']);
        $invoice = $this->invoiceOf((int) $r['participationId']);
        self::assertSame(15000, $invoice?->total->minor);
        self::assertStringContainsString('promoSuffix:EARLY,25 %,200.00 UAH', $invoice?->items[0]->description ?? '');

        // Used up; another day it would have expired anyway.
        [, $e] = $this->register(2, 'online', ['promoCode' => 'EARLY']);
        self::assertSame(['plugins.generic.conferenceManager.promo.error.usedUp'], $e['promoCode']);
        $later = $this->now->modify('+2 days');
        self::assertNotFalse($later);
        self::assertSame('plugins.generic.conferenceManager.promo.error.expired', $this->participants->checkPromo((new DbEditionRepository($this->db))->find(1, $this->editionId) ?? throw new \LogicException(), 'EARLY', $later)[1]);

        // A used code keeps its terms and cannot be deleted; a cancellation gives the use back.
        [, $e] = $this->promos->save($this->ctx, $this->editionId, (int) $code?->id, ['code' => 'EARLY', 'percent' => 30], 1) ?? [null, null];
        self::assertTrue($e?->has('code'));
        [, $e] = $this->promos->save($this->ctx, $this->editionId, (int) $code?->id, ['code' => 'EARLY', 'percent' => 25, 'maxUses' => 2], 1) ?? [null, null];
        self::assertSame([], $e?->toArray());
        self::assertFalse($this->promos->delete($this->ctx, $this->editionId, (int) $code?->id, 1));
        $this->participants->cancel($this->ctx, (int) $r['participationId'], 1, 'u1@example.org', false, $this->now);
        self::assertSame(0, $this->promos->ofEdition($this->ctx, $this->editionId)[0]->uses);

        // In the cabinet after the registration: the unpaid invoice is issued anew; 100 % gives a waiver.
        [$r2] = $this->register(2, 'online');
        $p2 = (int) $r2['participationId'];
        self::assertSame(20000, $this->invoiceOf($p2)?->total->minor);
        $mine = $this->participants->myParticipations($this->ctx, 2, 'u2@example.org', 'uk', $this->now);
        self::assertTrue($mine[0]['canApplyPromo']);
        [$ok, $e] = $this->participants->applyPromo($this->ctx, $p2, 3, 'u3@example.org', 'FREE', $this->now);
        self::assertFalse($ok, 'only the owner');
        [$ok, $e] = $this->participants->applyPromo($this->ctx, $p2, 2, 'u2@example.org', 'free', $this->now);
        self::assertTrue($ok, implode(',', array_keys($e->toArray())));
        $waiver = $this->invoiceOf($p2);
        self::assertSame(InvoiceStatus::Waived, $waiver?->status);
        self::assertSame('plugins.generic.conferenceManager.invoice.promoReason:FREE', $waiver?->waiverReason);
        [$ok, $e] = $this->participants->applyPromo($this->ctx, $p2, 2, 'u2@example.org', 'EARLY', $this->now);
        self::assertTrue($e->has('promoCode'), 'one code per participation');

        // Not after a payment.
        [$r3] = $this->register(3, 'online');
        $i3 = $this->invoiceOf((int) $r3['participationId']);
        $this->invoices->markPaid($this->ctx, (int) $i3?->id, '50', null, 1, $this->now);
        [$ok, $e] = $this->participants->applyPromo($this->ctx, (int) $r3['participationId'], 3, 'u3@example.org', 'EARLY', $this->now);
        self::assertSame(['plugins.generic.conferenceManager.promo.error.paid'], $e->toArray()['promoCode'] ?? []);
    }

    public function testWaitingList(): void
    {
        [$given, $e] = $this->participants->setSeats($this->ctx, $this->editionId, 'x', 1, $this->now);
        self::assertNull($given);
        self::assertTrue($e->has('seats'));
        $this->participants->setSeats($this->ctx, $this->editionId, 2, 1, $this->now);

        [$a] = $this->register(1);
        [$b] = $this->register(2);
        [$c] = $this->register(3);
        [$d] = $this->register(4);
        [$online] = $this->register(5, 'online');
        self::assertSame(['active', 'active', 'waitlisted', 'waitlisted', 'active'], [$a['status'], $b['status'], $c['status'], $d['status'], $online['status']]);
        self::assertNull($this->invoiceOf((int) $c['participationId']), 'no invoice on the waiting list');
        [, $e] = $this->register(3);
        self::assertSame(['plugins.generic.conferenceManager.listener.error.already'], $e['editionId'] ?? [], 'one place in the queue');

        $info = $this->participants->seats($this->ctx, $this->editionId, 'uk');
        self::assertSame(2, $info['seats'] ?? null);
        self::assertSame(2, $info['taken'] ?? null);
        self::assertSame([(int) $c['participationId'], (int) $d['participationId']], array_column($info['queue'] ?? [], 'participationId'));
        $mine = $this->participants->myParticipations($this->ctx, 4, 'u4@example.org', 'uk', $this->now);
        self::assertSame(2, $mine[0]['waitlistPosition']);
        self::assertTrue($mine[0]['canCancel']);

        // A cancelled seat goes to the first in the queue, with an invoice and a letter.
        $this->participants->cancel($this->ctx, (int) $a['participationId'], 1, 'u1@example.org', false, $this->now);
        self::assertSame('active', $this->db->table('conf_participations')->where('id', $c['participationId'])->value('status'));
        self::assertSame(20000, $this->invoiceOf((int) $c['participationId'])?->total->minor);
        self::assertSame([ParticipantMailTexts::SEAT, 'u3@example.org'], [$this->mailer->sent[0]['template'] ?? null, $this->mailer->sent[0]['email'] ?? null]);

        // Leaving the queue gives nothing to anyone; an online cancellation does not either.
        $this->participants->cancel($this->ctx, (int) $online['participationId'], 5, 'u5@example.org', false, $this->now);
        self::assertSame('waitlisted', $this->db->table('conf_participations')->where('id', $d['participationId'])->value('status'));

        // More seats: the queue moves at once; no limit takes everyone.
        [$given] = $this->participants->setSeats($this->ctx, $this->editionId, null, 1, $this->now);
        self::assertSame([(int) $d['participationId']], $given);
        self::assertSame([], $this->participants->seats($this->ctx, $this->editionId, 'uk')['queue'] ?? null);
    }
}
