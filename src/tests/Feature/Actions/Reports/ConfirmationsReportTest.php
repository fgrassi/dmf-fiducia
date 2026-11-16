<?php

namespace Tests\Feature\Actions\Reports;

use App\Actions\Reports\ConfirmationsReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ConfirmationsReportTest extends TestCase
{
    use LegacySchema;

    public function test_lists_each_kind_by_expected_arrival_including_the_last_day_and_filters_the_operation(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'ACME']);
        DB::table('usuario')->insert(['usuario_id' => 7, 'nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1]);
        $import = DB::table('embarque')->insertGetId(['booking' => 'IMP1', 'impor_expor' => 'i', 'cliente_id' => 1, 'saida_prevista' => '2026-10-20', 'chegada_prevista' => '2026-11-16 15:00:00']);
        $export = DB::table('embarque')->insertGetId(['booking' => 'EXP1', 'impor_expor' => 'e', 'chegada_prevista' => '2026-11-10']);
        $outside = DB::table('embarque')->insertGetId(['booking' => 'OLD', 'impor_expor' => 'i', 'chegada_prevista' => '2026-09-01']);
        foreach ([$import, $export, $outside] as $id) {
            DB::table('arrival_confirmation')->insert(['ref_embarque' => $id, 'created_by' => 7, 'created_at' => '2026-11-05']);
        }
        DB::table('departure_confirmation')->insert(['ref_embarque' => $export, 'created_by' => 7, 'created_at' => '2026-11-06']);

        $arrivals = app(ConfirmationsReport::class)('arrival', '2026-11-01', '2026-11-16');

        $this->assertSame(['IMP1', 'EXP1'], array_column($arrivals, 'booking'));
        $this->assertSame(['i', 'ACME', 'Ana'], [$arrivals[0]['operation'], $arrivals[0]['client'], $arrivals[0]['author']]);
        $this->assertSame(['IMP1'], array_column(app(ConfirmationsReport::class)('arrival', '2026-11-01', '2026-11-16', 'i'), 'booking'));
        $this->assertSame(['EXP1'], array_column(app(ConfirmationsReport::class)('departure', '2026-11-01', '2026-11-16'), 'booking'));
    }
}
