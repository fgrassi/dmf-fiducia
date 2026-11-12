<?php

namespace Tests\Feature\Models;

use App\Models\Armador;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ArmadorMediaAtrasoTest extends TestCase
{
    use LegacySchema;

    private function leg(string $trecho, int $ttPrevisto, int $ttEfetivo, int $armador = 1, bool $efetivo = true): void
    {
        DB::table('vw_transit_time')->insert([
            'armador_id' => $armador, 'armador' => 'A', 'origem' => 'X', 'destino' => 'Y', 'trecho' => $trecho, 'nome_navio' => 'N',
            'saida_prevista' => '2026-01-01', 'chegada_prevista' => '2026-01-10', 'tt_previsto' => $ttPrevisto,
            'saida_efetiva' => '2026-01-01', 'chegada_efetiva' => $efetivo ? '2026-01-12' : null, 'tt_efetivo' => $ttEfetivo,
        ]);
    }

    public function test_sums_the_delay_per_route_over_the_total_shipments_like_the_legacy(): void
    {
        $this->leg('T1', 10, 12);
        $this->leg('T1', 10, 14);   // T1: atraso 3, 2 embarques
        $this->leg('T2', 20, 21);   // T2: atraso 1, 1 embarque
        $this->leg('T1', 10, 99, efetivo: false); // sem chegada efetiva: ignorado
        $this->leg('T1', 10, 99, armador: 2);     // outro armador: ignorado

        $this->assertEqualsWithDelta((3 + 1) / 3, (new Armador(['armador_id' => 1]))->mediaAtraso(), 0.0001);
    }

    public function test_is_zero_without_shipments(): void
    {
        $this->assertSame(0.0, (new Armador(['armador_id' => 1]))->mediaAtraso());
    }
}
