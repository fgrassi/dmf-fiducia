<?php

namespace Tests\Feature\Actions\Booking\Creation;

use App\Actions\Booking\Creation\TransitTimeCheck;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class TransitTimeCheckTest extends TestCase
{
    use LegacySchema;

    #[DataProvider('deviations')]
    public function test_warns_when_the_transit_time_is_more_than_ten_percent_off_the_effective_average(int $days, ?string $warning): void
    {
        foreach ([[28, '2026-01-01'], [32, '2026-02-01'], [90, null]] as [$effective, $arrival]) {
            DB::table('vw_transit_time')->insert(['origem_id' => 1, 'destino_id' => 2, 'armador_id' => 3, 'nome_navio' => 'X', 'tt_efetivo' => $effective, 'saida_prevista' => '2026-01-01', 'chegada_prevista' => '2026-01-30', 'saida_efetiva' => '2026-01-01', 'chegada_efetiva' => $arrival]);
        }

        $messages = app(TransitTimeCheck::class)(1, 2, 3, null, $days);

        $this->assertSame($warning ? ["O Transit Time é {$warning} que a média de TT para esse trajeto! TT médio: 30,0"] : [], $messages);
    }

    public static function deviations(): array
    {
        return ['pouco acima' => [32, null], 'acima' => [34, 'MAIOR'], 'pouco abaixo' => [28, null], 'abaixo' => [26, 'MENOR']];
    }

    public function test_shows_the_consignee_agreed_time_and_says_nothing_without_the_route(): void
    {
        DB::table('transit_time')->insert(['armador_id' => 3, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'pessoa_id' => 9, 'transit_time' => 35, 'transit_time_seguranca' => 40]);

        $this->assertSame(['Transit time cadastrado para o consignee neste trajeto: médio 35, de segurança 40.'], app(TransitTimeCheck::class)(1, 2, 3, 9, null));
        $this->assertSame([], app(TransitTimeCheck::class)(1, 2, null, 9, 30));
    }
}
