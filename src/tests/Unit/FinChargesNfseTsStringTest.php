<?php

namespace Tests\Unit;

use App\Models\FinChargesNfse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FinChargesNfseTsStringTest extends TestCase
{
    /** @return array<string, array{string, int, string}> */
    public static function texts(): array
    {
        return [
            'travessão e aspas tipográficas' => ['Rua A – Sala “3”', 255, 'Rua A - Sala "3"'],
            'quebras de linha e espaços repetidos' => ["Rua  A\r\n\tCentro ", 255, 'Rua A Centro'],
            'caracteres acima de U+00FF somem' => ['Av. Brasil ✓ 100', 255, 'Av. Brasil 100'],
            'acentos latinos ficam' => ['São João – nº 5', 255, 'São João - nº 5'],
            'corta no limite sem espaço no fim' => ['Rua das Flores 123', 8, 'Rua das'],
        ];
    }

    #[DataProvider('texts')]
    public function test_sanitizes_text_for_the_national_xsd(string $text, int $max, string $expected): void
    {
        $this->assertSame($expected, FinChargesNfse::tsString($text, $max));
    }
}
