<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\UpdateEtd;
use App\Models\Embarque;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class UpdateEtdTest extends TestCase
{
    use LegacySchema;

    public function test_moves_the_eta_keeping_the_transit_time_and_logs_both_dates(): void
    {
        $booking = $this->booking(['saida_prevista' => '2026-11-01', 'chegada_prevista' => '2026-12-01']);

        app(UpdateEtd::class)($booking, Carbon::parse('2026-11-10'), 7);

        $booking->refresh();
        $this->assertSame('2026-11-10', substr($booking->saida_prevista, 0, 10));
        $this->assertSame('2026-12-10', substr($booking->chegada_prevista, 0, 10));
        $this->assertSame(
            ['Atualizado: Saída Prevista|O Campo Saída Prevista foi alterado de 01/11/2026 para 10/11/2026', 'Atualizado: Chegada Prevista|O Campo Chegada Prevista foi alterado de 01/12/2026 para 10/12/2026'],
            DB::table('rastreamento')->orderBy('id')->get()->map(fn ($r) => "{$r->assunto}|{$r->historico}")->all(),
        );
        $this->assertSame(
            [['saida_prevista', '2026-11-01', '2026-11-10'], ['chegada_prevista', '2026-12-01', '2026-12-10']],
            DB::table('rastreabilidade_alteracao')->orderBy('rastreabilidade_alteracao_id')->get()->map(fn ($r) => [$r->campo, $r->valor_antigo, $r->valor_novo])->all(),
        );
    }

    public function test_first_etd_is_set_without_history(): void
    {
        $booking = $this->booking();

        app(UpdateEtd::class)($booking, Carbon::parse('2026-11-10'), 7);

        $this->assertSame('2026-11-10', substr($booking->fresh()->saida_prevista, 0, 10));
        $this->assertSame(0, DB::table('rastreamento')->count());
    }

    public function test_blocks_after_the_departure_confirmation(): void
    {
        $booking = $this->booking(['saida_prevista' => '2026-11-01']);
        DB::table('departure_confirmation')->insert(['ref_embarque' => $booking->getKey(), 'created_by' => 7]);

        $this->expectException(DomainException::class);

        app(UpdateEtd::class)($booking, Carbon::parse('2026-11-10'), 7);
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'BKG1']));
    }
}
