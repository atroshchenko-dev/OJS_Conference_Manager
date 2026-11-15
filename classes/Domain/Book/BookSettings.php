<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Book;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;

/**
 * PUB-04: the title page and layout of the book of abstracts of an
 * edition. The texts are free, so that any institution can fill in the
 * imprint the way its publishing office requires; ISBN and UDC come from
 * the edition.
 */
final class BookSettings
{
    public const FORMATS = ['A4', 'A5'];
    public const ORDERS = ['program', 'sections', 'title'];
    private const K = 'plugins.generic.conferenceManager.book.error.';
    private const LIMITS = ['header' => 1000, 'title' => 500, 'subtitle' => 1000, 'place' => 200, 'imprint' => 5000, 'preface' => 20000];

    public function __construct(
        public readonly string $format = 'A5',
        public readonly string $order = 'program',
        public readonly string $header = '',
        public readonly string $title = '',
        public readonly string $subtitle = '',
        public readonly string $place = '',
        public readonly string $imprint = '',
        public readonly string $preface = '',
        public readonly bool $authorIndex = true,
        public readonly bool $keywords = true,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: self, 1: ValidationErrors}
     */
    public static function fromInput(array $input): array
    {
        $errors = new ValidationErrors();
        $format = (string) ($input['format'] ?? 'A5');
        if (!in_array($format, self::FORMATS, true)) {
            $errors->add('format', self::K . 'format');
            $format = 'A5';
        }
        $order = (string) ($input['order'] ?? 'program');
        if (!in_array($order, self::ORDERS, true)) {
            $errors->add('order', self::K . 'order');
            $order = 'program';
        }
        $text = [];
        foreach (self::LIMITS as $field => $max) {
            $value = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input[$field] ?? '')));
            if (mb_strlen($value) > $max) {
                $errors->add($field, self::K . 'long');
                $value = mb_substr($value, 0, $max);
            }
            $text[$field] = $value;
        }
        return [new self(
            $format,
            $order,
            $text['header'],
            $text['title'],
            $text['subtitle'],
            $text['place'],
            $text['imprint'],
            $text['preface'],
            filter_var($input['authorIndex'] ?? true, FILTER_VALIDATE_BOOL),
            filter_var($input['keywords'] ?? true, FILTER_VALIDATE_BOOL),
        ), $errors];
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return [
            'format' => $this->format, 'order' => $this->order, 'header' => $this->header, 'title' => $this->title,
            'subtitle' => $this->subtitle, 'place' => $this->place, 'imprint' => $this->imprint, 'preface' => $this->preface,
            'authorIndex' => $this->authorIndex, 'keywords' => $this->keywords,
        ];
    }
}
