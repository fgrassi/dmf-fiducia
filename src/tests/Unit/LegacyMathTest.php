<?php

namespace Tests\Unit;

use App\Services\LegacyMath;
use PHPUnit\Framework\TestCase;

class LegacyMathTest extends TestCase
{
    public function test_rounds_half_cents_up_like_php_5_6_even_when_the_float_is_just_below(): void
    {
        $this->assertSame(1311.26, LegacyMath::round(225 * 5.8278));
        $this->assertSame(1963.82, LegacyMath::round(70 * 5.6109 * 5));
        $this->assertSame(-1311.26, LegacyMath::round(-225 * 5.8278));
    }

    public function test_keeps_ordinary_values_and_zero(): void
    {
        $this->assertSame(551.23, LegacyMath::round(551.2299));
        $this->assertSame(0.0, LegacyMath::round(0.0));
        $this->assertSame(5.8278, LegacyMath::round(5.82775, 4));
    }
}
