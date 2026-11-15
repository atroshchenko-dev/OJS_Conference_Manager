<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use APP\plugins\generic\conferenceManager\classes\Application\LoadPolicy;
use APP\plugins\generic\conferenceManager\classes\Domain\Site\LoadProfile;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbLoadStatistics;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/** Plan, sections 15.1 and 15.2: load profiles, quiet period, letters per hour. */
final class LoadPolicyTest extends TestCase
{
    private Connection $db;
    private FakeSettings $settings;
    private LoadPolicy $policy;

    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            self::markTestSkipped('illuminate/database is not available');
        }
        $this->db = OjsStandIns::connection();
        (new ConferenceSchemaMigration())->usingConnection($this->db)->up();
        $this->settings = new FakeSettings();
        $this->policy = new LoadPolicy($this->settings, new DbLoadStatistics($this->db), new DateTimeZone('Europe/Kyiv'), new AuditLogger($this->db));
    }

    public function testProfiles(): void
    {
        self::assertSame(LoadProfile::Standard, $this->policy->profile());
        self::assertSame(['loadProfile' => 'standard', 'mailsPerHour' => 500, 'mailsPerHourOverride' => null, 'batchSize' => 30, 'jobsPerRequest' => 30, 'nightOnly' => false], $this->policy->siteSettings());
        self::assertSame([10, 5, 100, true], [LoadProfile::SharedHosting->batchSize(), LoadProfile::SharedHosting->jobsPerRequest(), LoadProfile::SharedHosting->mailsPerHour(), LoadProfile::SharedHosting->nightOnly()]);
        self::assertSame([30, 0, null, false], [LoadProfile::Provider->batchSize(), LoadProfile::Provider->jobsPerRequest(), LoadProfile::Provider->mailsPerHour(), LoadProfile::Provider->nightOnly()]);
        self::assertSame(LoadProfile::Standard, LoadProfile::fromSetting('bogus'));

        self::assertTrue($this->policy->saveSite(['loadProfile' => 'huge'], 1)->has('loadProfile'));
        self::assertTrue($this->policy->saveSite(['loadProfile' => 'provider', 'mailsPerHour' => '-5'], 1)->has('mailsPerHour'));
        self::assertTrue($this->policy->saveSite(['loadProfile' => 'provider', 'mailsPerHour' => '1e3'], 1)->has('mailsPerHour'));
        self::assertTrue($this->policy->saveSite(['loadProfile' => 'provider', 'mailsPerHour' => '2000'], 1)->isEmpty());
        self::assertSame(2000, $this->policy->mailsPerHour());
        self::assertTrue($this->policy->saveSite(['loadProfile' => 'shared_hosting', 'mailsPerHour' => ''], 1)->isEmpty());
        self::assertSame([100, 10], [$this->policy->mailsPerHour(), $this->policy->batchSize()]);
        self::assertSame(2, $this->db->table('conf_audit_log')->where('entity', 'load_profile')->count());
    }

    public function testNightWindowAndQuietPeriod(): void
    {
        $day = new DateTimeImmutable('2027-03-10T09:00:00Z'); // 11:00 in Kyiv
        $night = new DateTimeImmutable('2027-03-10T21:30:00Z'); // 23:30 in Kyiv
        self::assertNull($this->policy->deferUntil(1, $day), 'standard: any time');

        $this->policy->saveSite(['loadProfile' => 'shared_hosting'], null);
        self::assertSame('2027-03-10T20:00:00+00:00', $this->policy->deferUntil(1, $day)?->format(DATE_ATOM), '22:00 in Kyiv');
        self::assertNull($this->policy->deferUntil(1, $night));
        self::assertNull($this->policy->deferUntil(1, new DateTimeImmutable('2027-03-11T03:00:00Z')), '05:00 in Kyiv');

        // A submission deadline in 48 hours: mass work of that journal waits for it.
        $this->edition(1, 'cfp_open', '2027-03-11 23:00:00');
        $this->edition(2, 'cfp_closed', '2027-03-11 23:00:00');
        $this->policy->saveSite(['loadProfile' => 'standard'], null);
        self::assertSame('2027-03-11T23:01:00+00:00', $this->policy->deferUntil(1, $day)?->format(DATE_ATOM));
        self::assertNull($this->policy->deferUntil(2, $day), 'the call of the other journal is closed');
        self::assertNull($this->policy->deferUntil(1, new DateTimeImmutable('2027-03-08T09:00:00Z')), 'more than 48 hours before');

        // Shared hosting: after the deadline (01:01 in Kyiv) it is night, so it runs then.
        $this->policy->saveSite(['loadProfile' => 'shared_hosting'], null);
        self::assertSame('2027-03-11T23:01:00+00:00', $this->policy->deferUntil(1, $day)?->format(DATE_ATOM));
    }

    public function testLettersPerHourSharedBetweenJournals(): void
    {
        $now = new DateTimeImmutable('2027-03-10T09:00:00Z');
        self::assertSame(30, $this->policy->mailAllowance($now));
        $this->policy->saveSite(['loadProfile' => 'standard', 'mailsPerHour' => '40'], null);
        $a = $this->campaign(1, $this->edition(1, 'held', null));
        $b = $this->campaign(2, $this->edition(2, 'held', null));
        $this->recipients($a, 'sent', 25, '2027-03-10 08:30:00');
        $this->recipients($a, 'sent', 50, '2027-03-10 07:00:00'); // more than an hour ago
        $this->recipients($a, 'pending', 10, null);
        self::assertSame(15, $this->policy->mailAllowance($now), '40 an hour, 25 sent');
        $this->recipients($b, 'pending', 10, null);
        self::assertSame(8, $this->policy->mailAllowance($now), 'two journals share what is left');
        $this->recipients($b, 'sent', 15, '2027-03-10 08:59:00');
        self::assertSame(0, $this->policy->mailAllowance($now));
        $this->policy->saveSite(['loadProfile' => 'provider', 'mailsPerHour' => ''], null);
        self::assertSame(30, $this->policy->mailAllowance($now), 'no limit: a batch');
    }

    private function edition(int $context, string $status, ?string $deadline): int
    {
        return (int) $this->db->table('conf_editions')->insertGetId([
            'context_id' => $context, 'path' => 'e' . random_int(1, 1000000), 'status' => $status, 'abstract_deadline' => $deadline,
            'created_at' => '2027-01-01 00:00:00', 'updated_at' => '2027-01-01 00:00:00',
        ]);
    }

    private function campaign(int $context, int $edition): int
    {
        return (int) $this->db->table('conf_campaigns')->insertGetId([
            'context_id' => $context, 'edition_id' => $edition, 'locale' => 'uk', 'subject' => 's', 'body' => 'b', 'filter' => '{}', 'created_at' => '2027-01-01 00:00:00',
        ]);
    }

    private function recipients(int $campaign, string $status, int $n, ?string $sentAt): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $this->db->table('conf_campaign_recipients')->insert(['campaign_id' => $campaign, 'email' => "r{$i}@example.org", 'status' => $status, 'sent_at' => $sentAt]);
        }
    }
}
