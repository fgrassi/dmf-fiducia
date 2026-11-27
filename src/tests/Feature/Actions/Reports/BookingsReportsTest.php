<?php

namespace Tests\Feature\Actions\Reports;

use App\Actions\Reports\BookingsBySellerReport;
use App\Actions\Reports\BookingsReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingsReportsTest extends TestCase
{
    use LegacySchema;

    public function test_bookings_by_seller_build_the_four_lists_with_each_booking_once(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'ACME']);
        DB::table('vendedor')->insert([['vendedor_id' => 1, 'nome' => 'Maria'], ['vendedor_id' => 2, 'nome' => 'Pedro']]);
        $base = ['cliente_id' => 1, 'finalizado' => 'n', 'tipo_embarque' => 'm'];
        $import = DB::table('embarque')->insertGetId([...$base, 'booking' => 'IMP1', 'impor_expor' => 'i', 'vendedor_id' => 1, 'data_topo' => '2026-11-02', 'chegada_efetiva' => '2026-11-10']);
        $export = DB::table('embarque')->insertGetId([...$base, 'booking' => 'EXP1', 'impor_expor' => 'e', 'vendedor_id' => 1, 'data_topo' => '2026-11-03', 'chegada_efetiva' => '2026-11-12']);
        DB::table('embarque')->insert([...$base, 'booking' => 'OTHER', 'impor_expor' => 'i', 'vendedor_id' => 2, 'data_topo' => '2026-11-02']);
        DB::table('embarque')->insert([...$base, 'booking' => 'CANC', 'impor_expor' => 'i', 'vendedor_id' => 1, 'data_topo' => '2026-11-02', 'finalizado' => 'c']);
        DB::table('arrival_confirmation')->insert(['ref_embarque' => $import, 'created_by' => 1]);
        DB::table('departure_confirmation')->insert(['ref_embarque' => $export, 'created_by' => 1]);
        DB::table('fin_charges')->insert([['ref_type' => 1, 'ref_booking' => $import, 'created_at' => '2026-11-01', 'payment_date' => '2026-11-05'], ['ref_type' => 1, 'ref_booking' => $import, 'created_at' => '2026-11-01', 'payment_date' => '2026-11-06']]);

        $report = app(BookingsBySellerReport::class)(1, '2026-11-01', '2026-11-30');

        $this->assertSame(['IMP1', 'EXP1'], $report['closed']->pluck('booking')->all());
        $this->assertSame(['IMP1'], $report['imports']->pluck('booking')->all());
        $this->assertSame(['EXP1'], $report['exports']->pluck('booking')->all());
        $this->assertSame(['IMP1'], $report['payments']->pluck('booking')->all(), 'duas cobranças, uma reserva');
        $this->assertSame('2026-11-05', $report['payments'][0]->paid_at);
        $this->assertCount(3, app(BookingsBySellerReport::class)(null, '2026-11-01', '2026-11-30')['closed']);
        $this->assertCount(0, app(BookingsBySellerReport::class)(1, '2026-12-01', null)['closed']);
    }

    public function test_bookings_report_splits_maritime_lcl_and_air_and_totals_each_section(): void
    {
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Exportadora'], ['pessoa_id' => 2, 'nome' => 'Importadora']]);
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK']);
        DB::table('tipo_container')->insert([['tipo_container_id' => 1, 'nome' => '40HC', 'tipo' => '40'], ['tipo_container_id' => 2, 'nome' => '20DC', 'tipo' => '20'], ['tipo_container_id' => 20, 'nome' => 'LCL', 'tipo' => 'lcl'], ['tipo_container_id' => 21, 'nome' => 'AIR', 'tipo' => 'aereo']]);
        $base = ['pessoa_id_cliente' => 1, 'pessoa_id_importador' => 2, 'armador_id' => 1, 'data_topo' => '2026-11-10', 'finalizado' => 'n', 'impor_expor' => 'e', 'vendedor_id' => 3];
        $fcl = DB::table('embarque')->insertGetId([...$base, 'booking' => 'FCL1', 'tipo_embarque' => 'm', 'saida_prevista' => '2026-11-20']);
        $lcl = DB::table('embarque')->insertGetId([...$base, 'booking' => 'LCL1', 'tipo_embarque' => 'm']);
        $air = DB::table('embarque')->insertGetId([...$base, 'booking' => 'AIR1', 'tipo_embarque' => 'a']);
        DB::table('embarque')->insert([...$base, 'booking' => 'CAN1', 'tipo_embarque' => 'm', 'finalizado' => 'c']);
        DB::table('embarque')->insert([...$base, 'booking' => 'OLD1', 'tipo_embarque' => 'm', 'data_topo' => '2026-01-01']);
        $clearance = DB::table('embarque')->insertGetId([...$base, 'booking' => 'DES1', 'tipo_embarque' => 'd']);
        DB::table('mercadoria')->insert([
            ['embarque_id' => $fcl, 'tipo_container_id' => 1, 'quantidade' => '2', 'gross_weight' => null, 'cubature' => null],
            ['embarque_id' => $fcl, 'tipo_container_id' => 2, 'quantidade' => '1', 'gross_weight' => null, 'cubature' => null],
            ['embarque_id' => $lcl, 'tipo_container_id' => 20, 'quantidade' => null, 'gross_weight' => 800, 'cubature' => 4],
            ['embarque_id' => $air, 'tipo_container_id' => 21, 'quantidade' => null, 'gross_weight' => 120, 'cubature' => 0.7],
        ]);

        $report = app(BookingsReport::class)(['from' => '2026-11-01', 'to' => '2026-11-30']);

        $this->assertSame(['FCL1'], array_column($report['maritime']['rows'], 'booking'));
        $this->assertSame([2, 1, 1, 3], [$report['maritime']['rows'][0]['c40'], $report['maritime']['rows'][0]['c20'], $report['maritime']['totals']['bookings'], $report['maritime']['totals']['containers']]);
        $this->assertSame(['LCL1'], array_column($report['lcl']['rows'], 'booking'));
        $this->assertEqualsWithDelta(800.0, $report['lcl']['totals']['kg'], 0.01);
        $this->assertSame(['AIR1'], array_column($report['air']['rows'], 'booking'));
        $this->assertEqualsWithDelta(0.7, $report['air']['totals']['m3'], 0.01);
        $this->assertSame(['DES1'], array_column($report['clearance']['rows'], 'booking'));
        $this->assertSame($clearance, $report['clearance']['rows'][0]['id']);
        $this->assertSame(['CAN1'], array_column(app(BookingsReport::class)(['from' => '2026-11-01', 'status' => 'c'])['maritime']['rows'], 'booking'));
        $this->assertSame(['FCL1'], array_column(app(BookingsReport::class)(['equipment' => ['40'], 'sellers' => [3]])['maritime']['rows'], 'booking'));
        $this->assertSame([], app(BookingsReport::class)(['sections' => ['air']])['maritime']['rows']);
        // mais de um tipo marcado: basta ter um deles
        $this->assertSame(['FCL1'], array_column(app(BookingsReport::class)(['equipment' => ['40', 'tank'], 'sellers' => [3]])['maritime']['rows'], 'booking'));
        DB::table('embarque')->where('embarque_id', $fcl)->update(['cliente_id' => 2]);
        DB::table('grupo_empresa_membro')->insert(['grupo_empresa_id' => 4, 'pessoa_id' => 2, 'created_at' => '2026-11-01', 'created_by' => 1]);
        $group = app(BookingsReport::class)(['company_group' => 4]);
        $this->assertSame([['FCL1'], []], [array_column($group['maritime']['rows'], 'booking'), $group['air']['rows']]);
    }

    public function test_bookings_report_lists_insurance_bookings_and_those_with_insurance_charged(): void
    {
        DB::table('cia_seguro')->insert(['id' => 1, 'name' => 'Porto Seguro']);
        DB::table('despachante')->insert(['despachante_id' => 1, 'nome' => 'Despacho Ltda']);
        $base = ['data_topo' => '2026-11-10', 'finalizado' => 'n', 'despachante_id' => 1];
        $insurance = DB::table('embarque')->insertGetId([...$base, 'booking' => 'SEG1', 'tipo_embarque' => 's']);
        $charged = DB::table('embarque')->insertGetId([...$base, 'booking' => 'FCL1', 'tipo_embarque' => 'm']);
        DB::table('embarque')->insert([...$base, 'booking' => 'FCL2', 'tipo_embarque' => 'm']);
        DB::table('insurance_confirmation')->insert(['ref_embarque' => $insurance, 'created_by' => 1, 'ref_cia_seguro' => 1, 'valor_fatura' => 1000, 'valor_frete' => 100]);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $charged, 'ref_expense' => 79, 'selling_rate' => 10]);

        $rows = app(BookingsReport::class)(['sections' => ['insurance']])['insurance']['rows'];

        $this->assertSame(['SEG1', 'FCL1'], array_column($rows, 'booking'));
        $this->assertSame(['Porto Seguro', 1000.0, 'Despacho Ltda'], [$rows[0]['insurer'], $rows[0]['invoice_value'], $rows[0]['broker']]);
        $this->assertNull($rows[1]['insurer']);
    }
}
