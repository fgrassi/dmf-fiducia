<?php

namespace Tests\Feature\Actions\TrackingControl;

use App\Actions\TrackingControl\TrackingControlReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class TrackingControlReportTest extends TestCase
{
    use LegacySchema;

    private function booking(array $attributes): int
    {
        $id = DB::table('embarque')->insertGetId($attributes + ['tipo_embarque' => 'm', 'finalizado' => 'n', 'dteCadastro' => '2026-01-10']);
        DB::table('departure_confirmation')->insert(['ref_embarque' => $id, 'created_by' => 1, 'created_at' => '2026-01-11']);

        return $id;
    }

    public function test_import_lists_open_bookings_and_arrived_ones_without_arrival_confirmation_priority_first(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'ACME']);
        $open = $this->booking(['booking' => 'OPEN', 'impor_expor' => 'i', 'cliente_id' => 1, 'master_bl' => 'MBL1', 'free_time' => 7]);
        $priority = $this->booking(['booking' => 'PRIO', 'impor_expor' => 'i', 'flag_prioritario' => 1]);
        $arrivedUnconfirmed = $this->booking(['booking' => 'ARR', 'impor_expor' => 'i', 'finalizado' => 's']);
        $arrivedConfirmed = $this->booking(['booking' => 'DONE', 'impor_expor' => 'i', 'finalizado' => 's']);
        DB::table('arrival_confirmation')->insert(['ref_embarque' => $arrivedConfirmed, 'created_by' => 1]);
        $this->booking(['booking' => 'CANC', 'impor_expor' => 'i', 'finalizado' => 'c']);
        $this->booking(['booking' => 'AIR', 'impor_expor' => 'i', 'tipo_embarque' => 'a']);
        DB::table('embarque')->insert(['booking' => 'NODEP', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'finalizado' => 'n']);
        DB::table('bl')->insert(['bl_id' => 1, 'embarque_id' => $open]);
        DB::table('container')->insert([['bl_id' => 1, 'numero' => 'C1'], ['bl_id' => 1, 'numero' => 'C2']]);
        DB::table('transborda')->insert([
            ['embarque_id' => $open, 'descr' => 'Gate in', 'data_reg' => '2026-02-01'],
            ['embarque_id' => $open, 'descr' => 'Payment received 10', 'data_reg' => '2026-03-01'],
        ]);

        $rows = app(TrackingControlReport::class)('i');

        $this->assertSame('PRIO', $rows[0]['booking']);
        $this->assertEqualsCanonicalizing(['PRIO', 'OPEN', 'ARR'], array_column($rows, 'booking'));
        $row = collect($rows)->firstWhere('id', $open);
        $this->assertSame(['ACME', 'MBL1', 'C1, C2', 7, '2026-02-01'], [$row['client'], $row['mbl'], $row['containers'], $row['free_time'], $row['last_tracking']]);
        $this->assertNull(collect($rows)->firstWhere('id', $priority)['last_tracking']);
    }

    public function test_export_lists_bookings_without_actual_arrival_registered_since_2020(): void
    {
        $open = $this->booking(['booking' => 'EXP', 'impor_expor' => 'e', 'finalizado' => 'e']);
        $this->booking(['booking' => 'ARRIVED', 'impor_expor' => 'e', 'chegada_efetiva' => '2026-02-01']);
        $this->booking(['booking' => 'OLD', 'impor_expor' => 'e', 'dteCadastro' => '2019-12-31']);
        $this->booking(['booking' => 'IMP', 'impor_expor' => 'i']);
        DB::table('bl')->insert([['embarque_id' => $open, 'mbl_number' => 'M1'], ['embarque_id' => $open, 'mbl_number' => 'M2']]);

        $rows = app(TrackingControlReport::class)('e');

        $this->assertSame(['EXP'], array_column($rows, 'booking'));
        $this->assertSame(['M1, M2', 'Encerrado'], [$rows[0]['mbl'], $rows[0]['status']]);
    }
}
