<?php

namespace Tests\Feature\Actions\Reports;

use App\Actions\Reports\DailyMovementReport;
use App\Actions\Reports\TransitTimeByCarrierReport;
use App\Actions\Reports\TransitTimeByPortReport;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class TransitTimeAndMovementTest extends TestCase
{
    use LegacySchema;

    public function test_transit_time_by_carrier_averages_initial_and_actual_days_per_route(): void
    {
        $this->routes();
        $this->shipment(['etd_inicial' => '2026-10-01', 'eta_inicial' => '2026-10-21', 'saida_efetiva' => '2026-10-02', 'chegada_efetiva' => '2026-10-27']);
        $this->shipment(['etd_inicial' => '2026-10-10', 'eta_inicial' => '2026-10-40', 'saida_efetiva' => '2026-10-10', 'chegada_efetiva' => '2026-10-30']);
        $this->shipment(['etd_inicial' => '2026-10-10', 'eta_inicial' => '2026-10-30', 'saida_efetiva' => '2026-09-01', 'chegada_efetiva' => '2026-09-20']);
        $this->shipment(['etd_inicial' => '2026-10-10', 'eta_inicial' => null, 'saida_efetiva' => '2026-10-10', 'chegada_efetiva' => '2026-10-30']);

        $rows = app(TransitTimeByCarrierReport::class)('2026-10-01', '2026-10-31');

        $this->assertCount(1, $rows);
        $this->assertSame(['MAERSK', 'Santos', 'Roterdã', 'Brasil', 'Holanda', 2], [$rows[0]['carrier'], $rows[0]['origin'], $rows[0]['destination'], $rows[0]['originCountry'], $rows[0]['destinationCountry'], $rows[0]['count']]);
        $this->assertEqualsWithDelta(20.0, $rows[0]['planned'], 0.01);
        $this->assertEqualsWithDelta(22.5, $rows[0]['actual'], 0.01);
        $this->assertEqualsWithDelta(2.5, $rows[0]['delay'], 0.01);
        $this->assertSame([], app(TransitTimeByCarrierReport::class)('2026-10-01', '2026-10-31', 'a'));
        $this->assertCount(1, app(TransitTimeByCarrierReport::class)('2026-10-01', null, 'm', 1, 1, 2, [1], [2]));
    }

    public function test_transit_time_by_carrier_needs_the_start_date(): void
    {
        $this->expectException(DomainException::class);

        app(TransitTimeByCarrierReport::class)(null, null);
    }

    public function test_transit_time_by_port_reads_the_view_and_needs_an_origin_or_destination(): void
    {
        $row = ['armador' => 'MAERSK', 'origem' => 'Santos', 'origem_id' => 1, 'destino' => 'Roterdã', 'destino_id' => 2, 'pais_origem' => 'Brasil', 'pais_destino' => 'Holanda', 'nome_navio' => 'X'];
        DB::table('vw_transit_time')->insert([
            $row + ['saida_prevista' => '2026-10-01', 'chegada_prevista' => '2026-10-21', 'saida_efetiva' => '2026-10-01', 'chegada_efetiva' => '2026-10-26'],
            $row + ['saida_prevista' => '2026-10-01', 'chegada_prevista' => '2026-10-11', 'saida_efetiva' => '2026-10-01', 'chegada_efetiva' => '2026-10-16'],
            ['origem_id' => 9, 'destino_id' => 2] + $row + ['saida_prevista' => '2026-10-01', 'chegada_prevista' => null, 'saida_efetiva' => '2026-10-01', 'chegada_efetiva' => '2026-10-16'],
        ]);

        $this->assertSame([], app(TransitTimeByPortReport::class)(null, null));

        $rows = app(TransitTimeByPortReport::class)(1, null);

        $this->assertSame(2, $rows[0]['count']);
        $this->assertEqualsWithDelta(15.0, $rows[0]['planned'], 0.01);
        $this->assertEqualsWithDelta(20.0, $rows[0]['actual'], 0.01);
        $this->assertEqualsWithDelta(5.0, $rows[0]['delay'], 0.01);
    }

    public function test_daily_movement_counts_bookings_containers_and_flags_the_special_ones(): void
    {
        DB::table('vendedor')->insert(['vendedor_id' => 1, 'nome' => 'Maria Souza']);
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK']);
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'ACME', 'cnpj' => '1']);
        DB::table('tipo_container')->insert([['tipo_container_id' => 1, 'nome' => '40HC', 'tipo' => '40'], ['tipo_container_id' => 20, 'nome' => 'LCL', 'tipo' => 'lcl'], ['tipo_container_id' => 21, 'nome' => 'AIR', 'tipo' => 'aereo']]);
        $base = ['dteCadastro' => '2026-11-10', 'finalizado' => 'n', 'impor_expor' => 'e', 'vendedor_id' => 1, 'armador_id' => 1, 'cliente_id' => 1, 'de' => 'JOAO'];
        $fcl = DB::table('embarque')->insertGetId($base + ['booking' => 'FCL1', 'tipo_embarque' => 'm']);
        $lcl = DB::table('embarque')->insertGetId($base + ['booking' => 'LCL1', 'tipo_embarque' => 'm']);
        $air = DB::table('embarque')->insertGetId($base + ['booking' => 'AIR1', 'tipo_embarque' => 'a']);
        $insurance = DB::table('embarque')->insertGetId($base + ['booking' => 'SEG1', 'tipo_embarque' => 's']);
        DB::table('embarque')->insert($base + ['booking' => 'DES1', 'tipo_embarque' => 'd']);
        DB::table('embarque')->insert([...$base, 'booking' => 'OLD1', 'tipo_embarque' => 'm', 'dteCadastro' => '2026-10-01']);
        DB::table('embarque')->insert([...$base, 'booking' => 'CAN1', 'tipo_embarque' => 'm', 'finalizado' => 'c']);
        DB::table('mercadoria')->insert([
            ['embarque_id' => $fcl, 'tipo_container_id' => 1, 'quantidade' => '2', 'gross_weight' => null, 'cubature' => null],
            ['embarque_id' => $lcl, 'tipo_container_id' => 20, 'quantidade' => null, 'gross_weight' => 5000, 'cubature' => 3],
            ['embarque_id' => $air, 'tipo_container_id' => 21, 'quantidade' => null, 'gross_weight' => 100, 'cubature' => 0.5],
        ]);
        DB::table('fin_expenses_values')->insert([['ref_embarque' => $fcl, 'ref_expense' => 79, 'selling_rate' => 50], ['ref_embarque' => $lcl, 'ref_expense' => 618, 'selling_rate' => 0]]);

        $report = app(DailyMovementReport::class)('2026-11-01', '2026-11-30');

        $bookings = collect($report['bookings']);
        $this->assertSame(['FCL1', 'LCL1', 'AIR1', 'FCL1'], $bookings->pluck('booking')->all());
        $this->assertSame(['2*40HC', '5.000,00 kgs', '100,00 kgs'], $bookings->take(3)->pluck('containers')->all());
        $this->assertSame([false, true, false], $bookings->take(3)->pluck('star')->all());
        $this->assertSame(['Seguro', 'Exportação', 'Maria', 'MAERSK'], [$bookings[3]['type'], $bookings[0]['type'], $bookings[0]['seller'], $bookings[0]['carrier']]);
        $this->assertSame(['SEG1'], array_column($report['insurances'], 'booking'));
        $this->assertSame(['DES1'], array_column($report['clearances'], 'booking'));
        $this->assertSame(['fcl' => 1, 'containers' => 2, 'air' => 1, 'airKg' => 100.0, 'airM3' => 0.0, 'lcl' => 1, 'lclKg' => 5000.0, 'lclM3' => 0.0, 'insurance' => 2, 'clearance' => 1], $report['totals']);
        $this->assertSame(['FCL1', 'LCL1', 'AIR1', 'FCL1'], collect(app(DailyMovementReport::class)('2026-11-01', '2026-11-30', 'e')['bookings'])->pluck('booking')->all());
        $this->assertSame([], app(DailyMovementReport::class)('2026-11-01', '2026-11-30', 'i')['bookings']);
        $this->assertSame($insurance, $report['insurances'][0]['id']);
    }

    public function test_daily_movement_lists_the_quotes_of_the_period_and_marks_those_with_a_booking(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'ACME']);
        DB::table('usuario')->insert(['usuario_id' => 7, 'nome' => 'Ana Maria de Souza', 'login' => 'a', 'senha' => 'x', 'status' => 1]);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos', 'uncode' => 'BRSSZ'], ['porto_id' => 2, 'nome' => 'Roterdã', 'uncode' => 'NLRTM']]);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => '40HC']);
        DB::table('cotacao_expo')->insert(['cotacao_expo_id' => 12, 'pessoa_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'transit_time' => 1, 'armador_id' => 1, 'validade' => '2026-12-01', 'created_by' => 7, 'created_at' => '2026-11-10 08:00:00']);
        DB::table('cotacao_expo_container')->insert(['cotacao_expo_id' => 12, 'tipo_container_id' => 1, 'quantidade' => 1]);
        DB::table('embarque')->insert(['booking' => 'EXP1', 'cotacao_expo_id' => 12]);

        $quotes = app(DailyMovementReport::class)('2026-11-10', '2026-11-10')['quotes'];

        $this->assertSame(['AMS0012SSZRTM/2026', ['40HC'], '01/12/2026', true], [$quotes['expo'][0]['reference'], $quotes['expo'][0]['equipment'], $quotes['expo'][0]['validity'], $quotes['expo'][0]['booked']]);
        $this->assertSame([], app(DailyMovementReport::class)('2026-11-11', '2026-11-11')['quotes']['expo']);
    }

    private function routes(): void
    {
        DB::table('paises')->insert([['paises_id' => 1, 'nome' => 'Brasil'], ['paises_id' => 2, 'nome' => 'Holanda']]);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos', 'ref_pais' => 1], ['porto_id' => 2, 'nome' => 'Roterdã', 'ref_pais' => 2]]);
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK']);
    }

    /** @param array<string, mixed> $attributes */
    private function shipment(array $attributes): void
    {
        DB::table('embarque')->insert($attributes + ['booking' => 'B', 'tipo_embarque' => 'm', 'armador_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 2]);
    }
}
