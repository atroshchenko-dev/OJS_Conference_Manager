<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;

/**
 * The sum in words for an invoice (FEE-06), in Ukrainian or English, from
 * integer minor units. Ukrainian follows the usual accounting form:
 * "Сто п'ятдесят гривень 00 копійок". Other languages fall back to English.
 */
final class AmountInWords
{
    /** Ukrainian names: [gender, one, few, many] for the main and the minor unit. */
    private const UK_CURRENCIES = [
        'UAH' => [['f', 'гривня', 'гривні', 'гривень'], ['f', 'копійка', 'копійки', 'копійок']],
        'USD' => [['m', 'долар США', 'долари США', 'доларів США'], ['m', 'цент', 'центи', 'центів']],
        'EUR' => [['n', 'євро', 'євро', 'євро'], ['m', 'євроцент', 'євроценти', 'євроцентів']],
    ];

    private const EN_CURRENCIES = [
        'UAH' => ['hryvnia', 'hryvnias', 'kopiyka', 'kopiykas'],
        'USD' => ['US dollar', 'US dollars', 'cent', 'cents'],
        'EUR' => ['euro', 'euros', 'cent', 'cents'],
    ];

    private const UK_UNITS = [
        'm' => ['', 'один', 'два', 'три', 'чотири', "п'ять", 'шість', 'сім', 'вісім', "дев'ять"],
        'f' => ['', 'одна', 'дві', 'три', 'чотири', "п'ять", 'шість', 'сім', 'вісім', "дев'ять"],
        'n' => ['', 'одне', 'два', 'три', 'чотири', "п'ять", 'шість', 'сім', 'вісім', "дев'ять"],
    ];
    private const UK_TEENS = ['десять', 'одинадцять', 'дванадцять', 'тринадцять', 'чотирнадцять', "п'ятнадцять", 'шістнадцять', 'сімнадцять', 'вісімнадцять', "дев'ятнадцять"];
    private const UK_TENS = ['', '', 'двадцять', 'тридцять', 'сорок', "п'ятдесят", 'шістдесят', 'сімдесят', 'вісімдесят', "дев'яносто"];
    private const UK_HUNDREDS = ['', 'сто', 'двісті', 'триста', 'чотириста', "п'ятсот", 'шістсот', 'сімсот', 'вісімсот', "дев'ятсот"];
    /** [gender, one, few, many] of thousands, millions, billions. */
    private const UK_SCALES = [
        ['f', 'тисяча', 'тисячі', 'тисяч'],
        ['m', 'мільйон', 'мільйони', 'мільйонів'],
        ['m', 'мільярд', 'мільярди', 'мільярдів'],
    ];

    private const EN_SMALL = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
        'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    private const EN_TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    private const EN_SCALES = ['', 'thousand', 'million', 'billion'];

    public static function format(Money $money, string $locale): string
    {
        $exp = Money::exponent($money->currency);
        $base = 10 ** $exp;
        $major = intdiv($money->minor, $base);
        $minor = $money->minor % $base;
        return str_starts_with(strtolower($locale), 'uk')
            ? self::uk($major, $minor, $exp, $money->currency)
            : self::en($major, $minor, $exp, $money->currency);
    }

    private static function uk(int $major, int $minor, int $exp, string $currency): string
    {
        [$main, $sub] = self::UK_CURRENCIES[$currency] ?? [['m', $currency, $currency, $currency], ['m', '', '', '']];
        $words = $major === 0 ? 'нуль' : self::ukNumber($major, $main[0]);
        $out = $words . ' ' . self::ukForm($major, $main);
        if ($exp > 0) {
            $digits = str_pad((string) $minor, $exp, '0', STR_PAD_LEFT);
            $out .= ' ' . $digits . ($sub[1] !== '' ? ' ' . self::ukForm($minor, $sub) : '/' . (10 ** $exp));
        }
        return self::capitalize($out);
    }

    private static function ukNumber(int $n, string $gender): string
    {
        $groups = [];
        while ($n > 0) {
            $groups[] = $n % 1000;
            $n = intdiv($n, 1000);
        }
        $parts = [];
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            $g = $groups[$i];
            if ($g === 0) {
                continue;
            }
            if ($i === 0) {
                $parts[] = self::ukTriple($g, $gender);
            } else {
                $scale = self::UK_SCALES[$i - 1];
                $parts[] = self::ukTriple($g, $scale[0]) . ' ' . self::ukForm($g, $scale);
            }
        }
        return implode(' ', $parts);
    }

    private static function ukTriple(int $n, string $gender): string
    {
        $words = [];
        if ($n >= 100) {
            $words[] = self::UK_HUNDREDS[intdiv($n, 100)];
        }
        $rest = $n % 100;
        if ($rest >= 10 && $rest < 20) {
            $words[] = self::UK_TEENS[$rest - 10];
        } else {
            if ($rest >= 20) {
                $words[] = self::UK_TENS[intdiv($rest, 10)];
            }
            if ($rest % 10 > 0) {
                $words[] = self::UK_UNITS[$gender][$rest % 10];
            }
        }
        return implode(' ', $words);
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: string} $forms
     */
    private static function ukForm(int $n, array $forms): string
    {
        $last2 = $n % 100;
        $last = $n % 10;
        if ($last2 >= 11 && $last2 <= 14) {
            return $forms[3];
        }
        return match (true) {
            $last === 1 => $forms[1],
            $last >= 2 && $last <= 4 => $forms[2],
            default => $forms[3],
        };
    }

    private static function en(int $major, int $minor, int $exp, string $currency): string
    {
        $names = self::EN_CURRENCIES[$currency] ?? [$currency, $currency, '', ''];
        $out = self::enNumber($major) . ' ' . ($major === 1 ? $names[0] : $names[1]);
        if ($exp > 0) {
            $digits = str_pad((string) $minor, $exp, '0', STR_PAD_LEFT);
            $out .= ' ' . $digits . ($names[2] !== '' ? ' ' . ($minor === 1 ? $names[2] : $names[3]) : '/' . (10 ** $exp));
        }
        return self::capitalize($out);
    }

    private static function enNumber(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $parts = [];
        $scale = 0;
        while ($n > 0) {
            $g = $n % 1000;
            if ($g > 0) {
                array_unshift($parts, trim(self::enTriple($g) . ' ' . self::EN_SCALES[$scale]));
            }
            $n = intdiv($n, 1000);
            $scale++;
        }
        return implode(' ', $parts);
    }

    private static function enTriple(int $n): string
    {
        $words = [];
        if ($n >= 100) {
            $words[] = self::EN_SMALL[intdiv($n, 100)] . ' hundred';
        }
        $rest = $n % 100;
        if ($rest > 0 && $rest < 20) {
            $words[] = self::EN_SMALL[$rest];
        } elseif ($rest >= 20) {
            $words[] = self::EN_TENS[intdiv($rest, 10)] . ($rest % 10 ? '-' . self::EN_SMALL[$rest % 10] : '');
        }
        return implode(' ', $words);
    }

    private static function capitalize(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
