<?php

namespace App\Services;

/** Aritmética do PHP 5.6 do legado, para os valores gravados e enviados baterem centavo a centavo. */
class LegacyMath
{
    /**
     * round() do PHP < 8.4: pré-arredonda a 15 algarismos significativos antes de cortar, então 1311,255 (guardado como
     * 1311,2549999…) vira 1311,26. O PHP 8.4 não pré-arredonda e daria 1311,25.
     */
    public static function round(float $value, int $places = 2): float
    {
        if ($value === 0.0) {
            return 0.0;
        }

        $pre = 14 - (int) floor(log10(abs($value)));

        return $pre > $places && $pre - 15 < $places ? round(round($value, $pre), $places) : round($value, $places);
    }
}
