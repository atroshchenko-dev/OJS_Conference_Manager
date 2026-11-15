<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Book;

use APP\plugins\generic\conferenceManager\classes\Domain\Book\AbstractBookHtml;
use APP\plugins\generic\conferenceManager\classes\Domain\Book\BookSettings;
use PHPUnit\Framework\TestCase;

final class AbstractBookHtmlTest extends TestCase
{
    private const LABELS = ['contents' => 'Зміст', 'preface' => 'Передмова', 'index' => 'Покажчик', 'indexNote' => 'Номери тез.', 'keywords' => 'Ключові слова', 'isbn' => 'ISBN', 'udc' => 'УДК'];

    public function testSettingsValidation(): void
    {
        [$s, $e] = BookSettings::fromInput(['format' => 'A3', 'order' => 'x', 'title' => str_repeat('a', 501), 'keywords' => 'false']);
        self::assertSame(['format', 'order', 'title'], array_keys($e->toArray()));
        self::assertSame(['A5', 'program', 500, false], [$s->format, $s->order, mb_strlen($s->title), $s->keywords]);
        [$s, $e] = BookSettings::fromInput(['imprint' => "a\r\nb "]);
        self::assertTrue($e->isEmpty());
        self::assertSame("a\nb", $s->imprint);
        self::assertSame($s->toArray(), BookSettings::fromInput($s->toArray())[0]->toArray());
    }

    public function testBook(): void
    {
        [$s] = BookSettings::fromInput(['header' => "Міністерство\nУніверситет", 'place' => 'Київ', 'preface' => "Абзац 1\n\nАбзац 2", 'imprint' => 'Тези в авторській редакції']);
        $html = AbstractBookHtml::render($s, ['edition' => 'Конференція <2027>', 'isbn' => '978-617-00-0000-0', 'udc' => '378', 'year' => '2027', 'locale' => 'uk'], [
            ['title' => 'Секція 1', 'items' => [
                ['title' => 'Перша', 'authors' => [['name' => 'Петро Іваненко', 'affiliation' => 'КНУ'], ['name' => 'Ганна Єрмак', 'index' => 'Єрмак Г.', 'affiliation' => 'КНУ']], 'abstract' => '<p style="x" onclick="y">Текст <script>alert(1)</script><a href="z">посилання</a></p>', 'keywords' => ['освіта', 'ІТ']],
            ]],
            ['title' => 'Секція 2', 'items' => [
                ['title' => 'Друга', 'authors' => [['name' => 'Петро Іваненко', 'affiliation' => '']], 'abstract' => 'Без абзаців', 'keywords' => []],
            ]],
        ], self::LABELS);
        self::assertStringContainsString("Міністерство<br>\nУніверситет", $html);
        self::assertStringContainsString('Конференція &lt;2027&gt;', $html, 'the name of the edition when no title is given');
        self::assertStringContainsString('Київ — 2027', $html);
        self::assertStringContainsString('УДК 378<br>ISBN 978-617-00-0000-0', $html);
        self::assertStringContainsString('<p>Абзац 1</p><p>Абзац 2</p>', $html);
        self::assertStringContainsString('<tocentry content="1. Перша (Петро Іваненко, Ганна Єрмак)" level="1" />', $html);
        self::assertStringContainsString('<h2>2. Друга</h2>', $html);
        self::assertStringContainsString('<div class="affiliation">КНУ</div>', $html, 'one affiliation once');
        self::assertStringContainsString('<p>Текст посилання</p>', $html, 'no attributes, links or scripts');
        self::assertStringNotContainsString('alert', $html);
        self::assertStringContainsString('<p>Без абзаців</p>', $html);
        self::assertStringContainsString('<b>Ключові слова:</b> освіта, ІТ', $html);
        self::assertSame(1, substr_count($html, 'Ключові слова'), 'no keywords line without keywords');
        // Index: Єрмак before Іваненко in Ukrainian order, numbers of the abstracts.
        $index = substr($html, (int) strpos($html, '<div class="index">'));
        self::assertLessThan(strpos($index, 'Іваненко'), strpos($index, 'Єрмак Г.'));
        self::assertStringContainsString("Іваненко П.\u{00A0} 1, 2", $index);
    }

    public function testWithoutIndex(): void
    {
        [$s] = BookSettings::fromInput(['authorIndex' => false, 'keywords' => false, 'title' => 'Збірник']);
        $html = AbstractBookHtml::render($s, ['edition' => 'E', 'isbn' => null, 'udc' => null, 'year' => '2027'], [
            ['title' => '', 'items' => [['title' => 'T', 'authors' => [], 'abstract' => 'A', 'keywords' => ['k']]]],
        ], self::LABELS);
        self::assertStringNotContainsString('Покажчик', $html);
        self::assertStringNotContainsString('Ключові слова', $html);
        self::assertStringNotContainsString('class="codes"', $html);
        self::assertStringNotContainsString('<h1 class="group"></h1>', $html, 'no heading for a group without a title');
    }

    public function testIndexName(): void
    {
        self::assertSame('Іваненко П. О.', AbstractBookHtml::indexName('Петро Олегович Іваненко'));
        self::assertSame('Mononym', AbstractBookHtml::indexName(' Mononym '));
    }
}
