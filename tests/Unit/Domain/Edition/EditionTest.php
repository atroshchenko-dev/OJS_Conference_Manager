<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionValidator;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionWindows;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\PublicationMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EditionTest extends TestCase
{
    private const V = 'plugins.generic.conferenceManager.validation.';

    /**
     * @return array<string, mixed>
     */
    public static function validInput(array $overrides = []): array
    {
        return array_merge([
            'path' => 'conf-2027',
            'eventType' => 'conference',
            'submissionMode' => 'abstracts_and_papers',
            'publicationMode' => 'none',
            'format' => 'hybrid',
            'timezone' => 'Europe/Kyiv',
            'name' => ['uk' => 'Конференція 2027', 'en' => 'Conference 2027'],
            'startsAt' => '2027-05-20 09:00',
            'endsAt' => '2027-05-21 18:00',
            'cfpOpensAt' => '2027-01-15',
            'abstractDeadline' => '2027-03-01',
            'paperDeadline' => '2027-04-01',
            'isbn' => '978-0-306-40615-7',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: Edition, 1: ValidationErrors}
     */
    private function build(array $input, ?Edition $current = null): array
    {
        $errors = new ValidationErrors();
        $edition = Edition::fromArray(1, $input, $current?->id, 'UTC', $errors);
        $errors->merge((new EditionValidator())->validate($edition, 'uk', $current, $errors));
        return [$edition, $errors];
    }

    public function testValidEditionAndUtcDates(): void
    {
        [$edition, $errors] = $this->build(self::validInput());
        self::assertSame([], $errors->toArray());
        self::assertSame('2027-05-20T06:00:00Z', $edition->toArray()['startsAt']);
        self::assertSame('2027-05-20T09:00', $edition->toArray()['local']['startsAt']);
        self::assertSame('9780306406157', $edition->isbn);
        self::assertSame(EditionStatus::Draft, $edition->status);
    }

    public function testEventTypeDefaultsForWebinar(): void
    {
        [$edition, $errors] = $this->build([
            'path' => 'webinar-1', 'eventType' => 'webinar', 'name' => ['uk' => 'Вебінар'],
            'startsAt' => '2027-02-01 15:00', 'endsAt' => '2027-02-01 16:30',
        ]);
        self::assertSame([], $errors->toArray(), 'EVT-01: a webinar needs no call for papers');
        self::assertSame(SubmissionMode::None, $edition->submissionMode);
        self::assertSame(PublicationMode::None, $edition->publicationMode);
    }

    public function testDateRules(): void
    {
        [, $errors] = $this->build(self::validInput([
            'abstractDeadline' => '2027-01-10', 'paperDeadline' => '2027-01-05', 'endsAt' => '2027-05-19 09:00',
        ]));
        self::assertSame([self::V . 'dateOrder'], $errors->toArray()['abstractDeadline'] ?? null);
        self::assertSame([self::V . 'dateOrder'], $errors->toArray()['paperDeadline'] ?? null);
        self::assertSame([self::V . 'dateOrder'], $errors->toArray()['endsAt'] ?? null);

        [, $errors] = $this->build(self::validInput(['cfpOpensAt' => '', 'abstractDeadline' => 'soon']));
        self::assertTrue($errors->has('cfpOpensAt'));
        self::assertSame([self::V . 'date'], $errors->toArray()['abstractDeadline']);

        [, $errors] = $this->build(self::validInput(['submissionMode' => 'abstracts']));
        self::assertSame([self::V . 'paperDeadlineMode'], $errors->toArray()['paperDeadline'] ?? null);

        // Equal abstract and paper deadlines are allowed (abstract <= paper).
        [, $errors] = $this->build(self::validInput(['paperDeadline' => '2027-03-01']));
        self::assertFalse($errors->has('paperDeadline'));
    }

    public function testOtherRules(): void
    {
        [, $errors] = $this->build(self::validInput([
            'path' => 'Conf 2027', 'timezone' => 'Kyiv', 'isbn' => '978-0-306-40615-8',
            'publicationMode' => 'external', 'name' => ['en' => 'Only English'],
            'format' => 'zoom', 'hours' => '-3',
        ]));
        $e = $errors->toArray();
        self::assertSame([self::V . 'path'], $e['path']);
        self::assertSame([self::V . 'timezone'], $e['timezone']);
        self::assertSame([self::V . 'isbn'], $e['isbn']);
        self::assertSame([self::V . 'required'], $e['externalProceedingsUrl']);
        self::assertSame([self::V . 'requiredInPrimaryLocale'], $e['name']);
        self::assertSame([self::V . 'invalidChoice'], $e['format']);
        self::assertSame([self::V . 'integer'], $e['hours']);
    }

    public function testExternalUrlIsKeptOnlyInExternalMode(): void
    {
        [$edition] = $this->build(self::validInput(['publicationMode' => 'none', 'externalProceedingsUrl' => 'https://x.org']));
        self::assertNull($edition->externalProceedingsUrl);
        [$edition, $errors] = $this->build(self::validInput(['publicationMode' => 'external', 'externalProceedingsUrl' => 'https://repo.example.org/123']));
        self::assertSame('https://repo.example.org/123', $edition->externalProceedingsUrl);
        self::assertSame([], $errors->toArray());
    }

    public function testStatusTransitions(): void
    {
        [$current] = $this->build(self::validInput(['status' => 'held']));
        $current = $current->withId(5);
        [, $errors] = $this->build(self::validInput(['status' => 'cfp_open']), $current);
        self::assertSame([self::V . 'statusTransition'], $errors->toArray()['status']);
        [, $errors] = $this->build(self::validInput(['status' => 'published']), $current);
        self::assertFalse($errors->has('status'));
        [, $errors] = $this->build(self::validInput(['status' => 'archived']));
        self::assertTrue($errors->has('status'), 'a new edition starts as draft or with an open call');
    }

    public function testWindows(): void
    {
        [$edition] = $this->build(self::validInput(['status' => 'cfp_open']));
        $at = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s);
        self::assertFalse(EditionWindows::submissionsOpenAt($edition, $at('2027-01-14T21:59:59Z')));
        self::assertTrue(EditionWindows::submissionsOpenAt($edition, $at('2027-01-14T22:00:00Z')), 'opens at 00:00 Kyiv');
        self::assertTrue(EditionWindows::submissionsOpenAt($edition, $at('2027-02-28T21:59:59Z')));
        self::assertFalse(EditionWindows::submissionsOpenAt($edition, $at('2027-02-28T22:00:00Z')), 'closed at 00:00 Kyiv on the deadline day');
        self::assertTrue(EditionWindows::papersOpenAt($edition, $at('2027-03-15T00:00:00Z')));
        self::assertTrue(EditionWindows::registrationOpenAt($edition, $at('2027-05-20T00:00:00Z')));
        self::assertFalse(EditionWindows::registrationOpenAt($edition, $at('2027-05-21T16:00:00Z')));
        self::assertFalse(EditionWindows::submissionsOpenAt($edition->withStatus(EditionStatus::Draft), $at('2027-02-01T00:00:00Z')));
    }

    public function testCopyInputDropsIdentityAndDates(): void
    {
        [$edition] = $this->build(self::validInput());
        $copy = $edition->withId(3)->withIssueId(9)->toCopyInput();
        self::assertArrayNotHasKey('id', $copy);
        self::assertArrayNotHasKey('issueId', $copy);
        self::assertArrayNotHasKey('startsAt', $copy);
        self::assertSame('', $copy['path']);
        self::assertSame(['en' => 'Conference 2027', 'uk' => 'Конференція 2027'], $copy['name']);
    }
}
