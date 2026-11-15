<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Certificate;

use InvalidArgumentException;

/**
 * A QR code (ISO/IEC 18004) of a short text, for the verification link
 * printed on certificates (CRT-02). Byte mode, error correction level M,
 * versions 1–15 (up to 412 bytes), the mask with the lowest penalty.
 *
 * OJS 3.5 ships mPDF without the mpdf/qrcode package, so the code is
 * drawn as SVG that both mPDF and browsers render.
 */
final class QrCode
{
    private const MAX_VERSION = 15;
    /** Level M, index = version. */
    private const ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24];
    private const BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10];
    /** Format bits of level M. */
    private const ECL_BITS = 0;

    /** @var list<list<bool>> [y][x], true = dark */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $isFunction = [];
    public readonly int $size;
    public readonly int $mask;

    private function __construct(public readonly int $version, string $data, ?int $forceMask)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave(self::dataCodewords($data, $version)));

        $best = $forceMask;
        if ($best === null) {
            $min = PHP_INT_MAX;
            for ($m = 0; $m < 8; $m++) {
                $this->applyMask($m);
                $this->drawFormatBits($m);
                $penalty = $this->penalty();
                if ($penalty < $min) {
                    $min = $penalty;
                    $best = $m;
                }
                $this->applyMask($m);
            }
        }
        $this->mask = (int) $best;
        $this->applyMask($this->mask);
        $this->drawFormatBits($this->mask);
    }

    public static function encode(string $text, ?int $mask = null): self
    {
        if ($mask !== null && ($mask < 0 || $mask > 7)) {
            throw new InvalidArgumentException('mask');
        }
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $countBits = $v < 10 ? 8 : 16;
            if (4 + $countBits + strlen($text) * 8 <= self::numDataCodewords($v) * 8) {
                return new self($v, $text, $mask);
            }
        }
        throw new InvalidArgumentException('Text too long for a QR code');
    }

    public function isDark(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->size && $y < $this->size && $this->modules[$y][$x];
    }

    /** SVG with a quiet zone of 4 modules, scalable. */
    public function toSvg(int $border = 4): string
    {
        $dim = $this->size + $border * 2;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $dim * 4 . '" height="' . $dim * 4 . '" shape-rendering="crispEdges">'
            . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/><path d="' . $path . '" fill="#000000"/></svg>';
    }

    /** An <img> with the SVG as a data URI. */
    public function toImgTag(string $alt, string $width = '30mm'): string
    {
        return '<img src="data:image/svg+xml;base64,' . base64_encode($this->toSvg()) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" style="width:' . $width . ';height:' . $width . '">';
    }

    public static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    public static function numDataCodewords(int $ver): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::BLOCKS[$ver];
    }

    /** @return list<int> */
    public static function alignmentPositions(int $ver): array
    {
        if ($ver === 1) {
            return [];
        }
        $numAlign = intdiv($ver, 7) + 2;
        $step = (int) (ceil(($ver * 4 + 4) / ($numAlign * 2 - 2)) * 2);
        $result = [6];
        for ($i = 0, $pos = $ver * 4 + 10; $i < $numAlign - 1; $i++, $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    /** @return list<int> */
    private static function dataCodewords(string $data, int $ver): array
    {
        $bits = [];
        $push = static function (int $value, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $push(0b0100, 4);
        $push(strlen($data), $ver < 10 ? 8 : 16);
        foreach (str_split($data) as $c) {
            $push(ord($c), 8);
        }
        $capacity = self::numDataCodewords($ver) * 8;
        $push(0, min(4, $capacity - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $push($pad, 8);
        }
        $out = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $out[] = (int) bindec(implode('', $byte));
        }
        return $out;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private function addEccAndInterleave(array $data): array
    {
        $ver = $this->version;
        $numBlocks = self::BLOCKS[$ver];
        $eccLen = self::ECC_PER_BLOCK[$ver];
        $raw = intdiv(self::numRawDataModules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = ReedSolomon::divisor($eccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = ReedSolomon::remainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = -1;
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $size = $this->size;
        for ($i = 0; $i < $size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $xx = $cx + $dx;
                    $yy = $cy + $dy;
                    if ($xx >= 0 && $xx < $size && $yy >= 0 && $yy < $size) {
                        $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = self::alignmentPositions($this->version);
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = $this->version << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->setFunction($a, $b, $bit);
                $this->setFunction($b, $a, $bit);
            }
        }
    }

    public static function formatBits(int $mask): int
    {
        $data = self::ECL_BITS << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        return ($data << 10 | $rem) ^ 0x5412;
    }

    private function drawFormatBits(int $mask): void
    {
        $bits = self::formatBits($mask);
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }
        $size = $this->size;
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $size - 8, true);
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $size = $this->size;
        $total = count($data) * 8;
        $i = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    public static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if (!$this->isFunction[$y][$x] && self::maskBit($mask, $x, $y)) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The penalty of ISO/IEC 18004 §7.8.3: runs, blocks, finder-like patterns, balance. */
    private function penalty(): int
    {
        $size = $this->size;
        $result = 0;
        $dark = 0;
        $lines = [];
        for ($y = 0; $y < $size; $y++) {
            $row = '';
            $col = '';
            for ($x = 0; $x < $size; $x++) {
                $row .= $this->modules[$y][$x] ? '1' : '0';
                $col .= $this->modules[$x][$y] ? '1' : '0';
                $dark += $this->modules[$y][$x] ? 1 : 0;
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            preg_match_all('/0{5,}|1{5,}/', $line, $m);
            foreach ($m[0] as $run) {
                $result += 3 + strlen($run) - 5;
            }
            $result += 40 * (substr_count('0000' . $line, '00001011101') + substr_count($line . '0000', '10111010000'));
        }
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }
        $total = $size * $size;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $result + max(0, $k) * 10;
    }
}
