<?php

namespace Tests\Feature\Actions\Reports;

use App\Actions\Reports\LocalChargesReport;
use App\Actions\Reports\NegotiatedRatesReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class MailAndRatesReportsTest extends TestCase
{
    use LegacySchema;

    public function test_negotiated_rates_lists_the_period_inclusive_of_the_last_day_and_filters_the_operation(): void
    {
        $client = DB::table('pessoa')->insertGetId(['nome' => 'ACME', 'cnpj' => '11.111']);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('usuario')->insert(['usuario_id' => 7, 'nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1]);
        $import = $this->charge(['booking' => 'IMP1', 'impor_expor' => 'i', 'cliente_id' => $client], 1500);
        $export = $this->charge(['booking' => 'EXP1', 'impor_expor' => 'e'], 300);
        $this->rate($import, '2026-11-16 15:30:00', 5.25, 'combinado');
        $this->rate($export, '2026-11-10 09:00:00', 5.1, 'x');
        $this->rate($import, '2026-09-01 09:00:00', 4, 'fora');

        $rows = app(NegotiatedRatesReport::class)('2026-11-01', '2026-11-16');

        $this->assertSame(['EXP1', 'IMP1'], array_column($rows, 'booking'));
        $this->assertSame(['Importação', 'ACME (11.111)', 'Dólar (US$)', 1500.0, 5.25, 'Ana', 'combinado'], [$rows[1]['operation'], $rows[1]['client'], $rows[1]['currency'], $rows[1]['total'], $rows[1]['rate'], $rows[1]['author'], $rows[1]['note']]);
        $this->assertSame(['IMP1'], array_column(app(NegotiatedRatesReport::class)('2026-11-01', '2026-11-16', 'i'), 'booking'));
    }

    public function test_local_charges_group_by_carrier_and_port_and_skip_expiring_rows(): void
    {
        DB::table('armador')->insert([['armador_id' => 1, 'nome' => 'MAERSK'], ['armador_id' => 2, 'nome' => 'MSC']]);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos'], ['porto_id' => 2, 'nome' => 'Itajaí']]);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'Container', 'acronym' => 'CTR']);
        DB::table('fin_expenses')->insert([['id' => 1, 'name' => 'THC', 'ref_unity' => 1], ['id' => 2, 'name' => 'Lacre', 'ref_unity' => null]]);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => '40HC']);
        DB::table('cmn_armador_porto')->insert([['id' => 1, 'ref_armador' => 1, 'ref_porto' => 1], ['id' => 2, 'ref_armador' => 1, 'ref_porto' => 2], ['id' => 3, 'ref_armador' => 2, 'ref_porto' => 1]]);
        $matrix = fn (int $pair, int $expense, string $type, ?string $dueTo = null) => DB::table('fin_expenses_matrix')->insert(['ref_armador_porto' => $pair, 'ref_equipamento' => 1, 'ref_expense' => $expense, 'type' => $type, 'expense_value' => 100.5, 'imo_value' => 0, 'due_to' => $dueTo, 'created_by' => 1, 'ref_currency' => 2, 'billing' => 'p', 'due_from' => '2026-01-01']);
        $matrix(1, 1, 'i');
        $matrix(1, 2, 'i');
        $matrix(2, 1, 'e');
        $matrix(3, 1, 'i');
        $matrix(3, 2, 'i', '2026-12-31');

        $all = app(LocalChargesReport::class)(null, null, null);

        $this->assertSame(['MAERSK', 'MSC'], array_column($all, 'carrier'));
        $this->assertSame(['Itajaí', 'Santos'], array_column($all[0]['ports'], 'port'));
        $this->assertSame(['Lacre', 'THC / CTR'], array_column($all[0]['ports'][1]['charges'], 'expense'));
        $this->assertSame(['40HC', 100.5, 'US$'], [$all[0]['ports'][1]['charges'][0]['equipment'], $all[0]['ports'][1]['charges'][0]['value'], $all[0]['ports'][1]['charges'][0]['symbol']]);
        $this->assertCount(1, $all[1]['ports'][0]['charges'], 'a taxa com vencimento fica de fora');
        $this->assertSame(['Santos'], array_column(app(LocalChargesReport::class)('i', 1, 1)[0]['ports'], 'port'));
        $this->assertSame(['Itajaí'], array_column(app(LocalChargesReport::class)('e', null, null)[0]['ports'], 'port'));
    }

    /** @param array<string, mixed> $booking */
    private function charge(array $booking, float $total): int
    {
        $id = DB::table('embarque')->insertGetId($booking);

        return DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => $id, 'total_value' => $total, 'created_at' => '2026-11-01']);
    }

    private function rate(int $charge, string $createdAt, float $rate, string $note): void
    {
        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $charge, 'note' => $note, 'created_at' => $createdAt, 'created_by' => 7, 'custom_rate' => $rate]);
    }
}
