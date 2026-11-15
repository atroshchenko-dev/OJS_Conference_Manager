<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Certificate;

/** Reed–Solomon error correction over GF(2^8) with the QR polynomial 0x11D. */
final class ReedSolomon
{
    public static function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    /** @return array<int, int> coefficients from the highest power, the leading 1 left out */
    public static function divisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::multiply($root, 0x02);
        }
        return $result;
    }

    /**
     * @param list<int> $data
     * @param array<int, int> $divisor
     * @return array<int, int>
     */
    public static function remainder(array $data, array $divisor): array
    {
        $n = count($divisor);
        $result = array_fill(0, $n, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $n; $i++) {
                $result[$i] ^= self::multiply($divisor[$i], $factor);
            }
        }
        return $result;
    }
}
