<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Certificate;

use APP\plugins\generic\conferenceManager\classes\Domain\Certificate\QrCode;
use PHPUnit\Framework\TestCase;

/**
 * The encoder is checked by a decoder written separately here: it finds
 * the format by trying all 32 BCH words, removes the mask, reads the
 * codewords in the zigzag order of ISO/IEC 18004 §7.7.3, checks every
 * Reed–Solomon block by its syndromes and parses the byte segment.
 */
final class QrCodeTest extends TestCase
{
    /** Level M: [blocks, ecc per block] per version. */
    private const M = [1 => [1, 10], [1, 16], [1, 26], [2, 18], [2, 24], [4, 16], [4, 18], [4, 22], [5, 22], [5, 26], [5, 30], [8, 22], [9, 22], [9, 24], [10, 24]];

    public function testKnownSizes(): void
    {
        self::assertSame(1, QrCode::encode('HELLO')->version);
        self::assertSame(21, QrCode::encode('HELLO')->size);
        self::assertSame([6, 22, 38], QrCode::alignmentPositions(7));
        self::assertSame([6, 26, 48, 70], QrCode::alignmentPositions(15));
        self::assertSame(16, QrCode::numDataCodewords(1));
        self::assertSame(0b101010000010010, QrCode::formatBits(0), 'M, mask 0 (table C.1)');
    }

    public function testRoundTripAcrossVersionsAndMasks(): void
    {
        $base = 'https://journals.example.edu.ua/index.php/conf/conference/verify/';
        foreach ([1, 14, 40, 60, 90, 120, 160, 200] as $len) {
            $text = substr(str_repeat($base . 'd2f1c0e8-6b7a-4c39-9f4e-0a1b2c3d4e5f', 6), 0, $len);
            $qr = QrCode::encode($text);
            self::assertSame($text, $this->decode($qr), "auto mask, {$len} bytes, version {$qr->version}");
            foreach ([0, 3, 5, 7] as $mask) {
                self::assertSame($text, $this->decode(QrCode::encode($text, $mask)), "mask {$mask}, {$len} bytes");
            }
        }
    }

    public function testFinderPatternsAndSvg(): void
    {
        $qr = QrCode::encode('x');
        foreach ([[0, 0], [14, 0], [0, 14]] as [$ox, $oy]) {
            self::assertTrue($qr->isDark($ox, $oy));
            self::assertTrue($qr->isDark($ox + 3, $oy + 3));
            self::assertFalse($qr->isDark($ox + 1, $oy + 1));
        }
        self::assertStringStartsWith('<svg', $qr->toSvg());
        self::assertStringContainsString('data:image/svg+xml;base64,', $qr->toImgTag('QR'));
        $this->expectException(\InvalidArgumentException::class);
        QrCode::encode(str_repeat('a', 600));
    }

    private function decode(QrCode $qr): string
    {
        $size = $qr->size;
        $v = $qr->version;
        // Format: the first copy around the top left finder.
        $read = 0;
        $coords = [];
        for ($i = 0; $i <= 5; $i++) {
            $coords[$i] = [8, $i];
        }
        $coords[6] = [8, 7];
        $coords[7] = [8, 8];
        $coords[8] = [7, 8];
        for ($i = 9; $i < 15; $i++) {
            $coords[$i] = [14 - $i, 8];
        }
        foreach ($coords as $i => [$x, $y]) {
            $read |= ($qr->isDark($x, $y) ? 1 : 0) << $i;
        }
        $mask = null;
        for ($ecl = 0; $ecl < 4; $ecl++) {
            for ($m = 0; $m < 8; $m++) {
                $data = $ecl << 3 | $m;
                $rem = $data;
                for ($k = 0; $k < 10; $k++) {
                    $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
                }
                if ((($data << 10 | $rem) ^ 0x5412) === $read) {
                    self::assertSame(0, $ecl, 'level M');
                    $mask = $m;
                }
            }
        }
        self::assertNotNull($mask, 'format word found');

        $reserved = $this->reserved($size, $v);
        $bits = [];
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            $up = $this->columnPairGoesUp($right, $size);
            for ($k = 0; $k < $size; $k++) {
                $y = $up ? $size - 1 - $k : $k;
                foreach ([$right, $right - 1] as $x) {
                    if (!isset($reserved[$y][$x])) {
                        $dark = ($qr->isDark($x, $y) xor QrCode::maskBit($mask, $x, $y));
                        $bits[] = $dark ? 1 : 0;
                    }
                }
            }
        }
        [$blocks, $ecc] = self::M[$v];
        $total = intdiv(count($bits), 8);
        $codewords = [];
        for ($i = 0; $i < $total; $i++) {
            $codewords[] = (int) bindec(implode('', array_slice($bits, $i * 8, 8)));
        }
        $short = intdiv($total, $blocks);
        $numShort = $blocks - $total % $blocks;
        $dataLens = [];
        for ($b = 0; $b < $blocks; $b++) {
            $dataLens[$b] = $short - $ecc + ($b < $numShort ? 0 : 1);
        }
        $blockData = array_fill(0, $blocks, []);
        $p = 0;
        for ($i = 0; $i < max($dataLens); $i++) {
            for ($b = 0; $b < $blocks; $b++) {
                if ($i < $dataLens[$b]) {
                    $blockData[$b][] = $codewords[$p++];
                }
            }
        }
        $blockEcc = array_fill(0, $blocks, []);
        for ($i = 0; $i < $ecc; $i++) {
            for ($b = 0; $b < $blocks; $b++) {
                $blockEcc[$b][] = $codewords[$p++];
            }
        }
        $data = [];
        for ($b = 0; $b < $blocks; $b++) {
            $this->assertNoErrors(array_merge($blockData[$b], $blockEcc[$b]), $ecc);
            $data = array_merge($data, $blockData[$b]);
        }
        $stream = '';
        foreach ($data as $byte) {
            $stream .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        self::assertSame('0100', substr($stream, 0, 4), 'byte mode');
        $countBits = $v < 10 ? 8 : 16;
        $count = (int) bindec(substr($stream, 4, $countBits));
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $out .= chr((int) bindec(substr($stream, 4 + $countBits + $i * 8, 8)));
        }
        return $out;
    }

    private function columnPairGoesUp(int $right, int $size): bool
    {
        // Column pairs counted from the right edge; the first one goes up.
        $index = $right > 6 ? intdiv($size - 1 - $right, 2) : intdiv($size - 1 - $right - 1, 2);
        return $index % 2 === 0;
    }

    /** @return array<int, array<int, true>> */
    private function reserved(int $size, int $v): array
    {
        $r = [];
        $mark = static function (int $x, int $y) use (&$r, $size): void {
            if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                $r[$y][$x] = true;
            }
        };
        for ($i = 0; $i < 9; $i++) {
            for ($j = 0; $j < 9; $j++) {
                $mark($i, $j);
            }
        }
        for ($i = 0; $i < 9; $i++) {
            for ($j = 0; $j < 8; $j++) {
                $mark($size - 8 + $j, $i);
                $mark($i, $size - 8 + $j);
            }
        }
        for ($i = 0; $i < $size; $i++) {
            $mark(6, $i);
            $mark($i, 6);
        }
        if ($v >= 2) {
            $n = intdiv($v, 7) + 2;
            $last = $size - 7;
            $step = (int) (2 * ceil(($last - 6) / (2 * ($n - 1))));
            $pos = [6];
            for ($k = $n - 2; $k >= 0; $k--) {
                $pos[] = $last - $k * $step;
            }
            foreach ($pos as $cx) {
                foreach ($pos as $cy) {
                    if (($cx === 6 && $cy === 6) || ($cx === 6 && $cy === $last) || ($cx === $last && $cy === 6)) {
                        continue;
                    }
                    for ($dx = -2; $dx <= 2; $dx++) {
                        for ($dy = -2; $dy <= 2; $dy++) {
                            $mark($cx + $dx, $cy + $dy);
                        }
                    }
                }
            }
        }
        if ($v >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $mark($size - 11 + $j, $i);
                    $mark($i, $size - 11 + $j);
                }
            }
        }
        return $r;
    }

    /** @param list<int> $codewords data then ECC, highest power first */
    private function assertNoErrors(array $codewords, int $ecc): void
    {
        $alpha = 1;
        for ($i = 0; $i < $ecc; $i++) {
            $s = 0;
            foreach ($codewords as $c) {
                $s = $this->gfMul($s, $alpha) ^ $c;
            }
            self::assertSame(0, $s, "syndrome {$i}");
            $alpha = $this->gfMul($alpha, 2);
        }
    }

    private function gfMul(int $a, int $b): int
    {
        $p = 0;
        while ($b > 0) {
            if ($b & 1) {
                $p ^= $a;
            }
            $a <<= 1;
            if ($a & 0x100) {
                $a ^= 0x11D;
            }
            $b >>= 1;
        }
        return $p;
    }
}
