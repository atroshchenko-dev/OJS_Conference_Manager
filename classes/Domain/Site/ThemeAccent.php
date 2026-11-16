<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Site;

/**
 * The brand colour of the journal's theme, turned into the plugin's public
 * colour tokens (DESIGN.md). Themes built on the OJS Default theme keep it in
 * the `baseColour` option; with it, conference pages use the same colour as
 * the rest of the journal instead of a colour of their own.
 */
final class ThemeAccent
{
    private const WHITE = 'ffffff';
    private const DARK = '222222';
    private const TEXT_CONTRAST = 4.5;

    private function __construct(private readonly string $hex)
    {
    }

    /** A theme colour such as "#1E6292" or "#abc"; null for anything else. */
    public static function fromColour(mixed $value): ?self
    {
        if (!is_string($value) || !preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $m)) {
            return null;
        }
        $hex = strtolower($m[1]);
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return new self($hex);
    }

    public function accent(): string
    {
        return '#' . $this->hex;
    }

    /** Text on a button filled with the accent: white or dark, whichever reads better. */
    public function onAccent(): string
    {
        return self::contrast($this->hex, self::WHITE) >= self::contrast($this->hex, self::DARK) ? '#fff' : '#' . self::DARK;
    }

    /** The accent as text on white: darkened until it reaches 4.5:1. */
    public function text(): string
    {
        for ($i = 0; $i <= 20; $i++) {
            $hex = self::mix($this->hex, '000000', $i * 0.05);
            if (self::contrast($hex, self::WHITE) >= self::TEXT_CONTRAST) {
                return '#' . $hex;
            }
        }
        return '#' . self::DARK;
    }

    /** A light tint of the accent for highlighted blocks. */
    public function surface(): string
    {
        return '#' . self::mix(self::WHITE, $this->hex, 0.08);
    }

    /** The tokens for public pages, as a style block body. */
    public function css(): string
    {
        return ':root{--cm-accent:' . $this->accent() . ';--cm-on-accent:' . $this->onAccent()
            . ';--cm-accent-text:' . $this->text() . ';--cm-accent-surface:' . $this->surface() . '}';
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance(ltrim($a, '#'));
        $lb = self::luminance(ltrim($b, '#'));
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $c = array_map(static function (string $pair): float {
            $v = hexdec($pair) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** $from moved towards $to by $share (0…1). */
    private static function mix(string $from, string $to, float $share): string
    {
        $a = str_split($from, 2);
        $b = str_split($to, 2);
        $out = '';
        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', (int) round(hexdec($a[$i]) + (hexdec($b[$i]) - hexdec($a[$i])) * $share));
        }
        return $out;
    }
}
