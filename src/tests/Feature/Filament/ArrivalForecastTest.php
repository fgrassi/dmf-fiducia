<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\ArrivalForecastReport;
use App\Filament\Livewire\ReportResult;
use App\Filament\Pages\ArrivalForecast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ArrivalForecastTest extends TestCase
{
    use LegacySchema;

    public function test_needs_financial_and_manager_or_supervisor(): void
    {
        $this->actingAs($this->user('financial'))->get(ArrivalForecast::getUrl())->assertForbidden();
        $this->actingAs($this->user('manager'))->get(ArrivalForecast::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial', 'supervisor'))->get(ArrivalForecast::getUrl())->assertOk();
    }

    public function test_without_dates_nothing_is_queried(): void
    {
        $this->sea();

        $this->assertSame(['sea' => [], 'other' => []], app(ArrivalForecastReport::class)('i', null, null));
    }

    public function test_period_type_and_cancelled_filter_the_bookings(): void
    {
        $this->sea();
        DB::table('embarque')->insert([
            ['embarque_id' => 2, 'booking' => 'OUT', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'finalizado' => 'n', 'chegada_prevista' => '2026-12-01', 'saida_prevista' => null],
            ['embarque_id' => 3, 'booking' => 'CANC', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'finalizado' => 'c', 'chegada_prevista' => '2026-11-12', 'saida_prevista' => null],
            ['embarque_id' => 4, 'booking' => 'EXPO', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'finalizado' => 'n', 'saida_prevista' => '2026-11-12', 'chegada_prevista' => null],
        ]);

        $report = app(ArrivalForecastReport::class)('i', '2026-11-01', '2026-11-30');

        $this->assertSame(['IMP1'], array_column($report['sea'], 'booking'));
        $this->assertSame(['EXPO'], array_column(app(ArrivalForecastReport::class)('e', '2026-11-01', null)['sea'], 'booking'));
    }

    public function test_sea_booking_is_split_by_bl_with_factor_minimum_discount_and_conversion(): void
    {
        $this->sea();

        $group = app(ArrivalForecastReport::class)('i', '2026-11-01', '2026-11-30')['sea'][0]['groups'][0];
        $lines = collect($group['expenses'])->keyBy('name');

        $this->assertSame('BL-1', $group['title']);
        // 2 containers de 20' de 10 m³ cada: fator 2; US$ 100 × 2 = 200 a 5,10 (5,00 + 2%) = 1.020
        $this->assertSame(2.0, $lines['THC/CTN']['factor']);
        $this->assertSame(200.0, $lines['THC/CTN']['total_cost']);
        $this->assertEqualsWithDelta(1020.0, $lines['THC/CTN']['converted'], 0.001);
        // por BL: fator 1, mínimo de R$ 50 prevalece sobre os R$ 10
        $this->assertSame(50.0, $lines['DOC/BL']['total_cost']);
        $this->assertSame(50.0, $lines['DOC/BL']['converted']);
        // despesa de desconto sai negativa
        $this->assertSame(-30.0, $lines['DESCONTO/BL']['total_cost']);
        $this->assertEqualsWithDelta(1020.0 + 50 - 30, $group['total'], 0.001);
    }

    public function test_cotation_of_the_client_overrides_the_default_percentage(): void
    {
        $this->sea();
        DB::table('embarque')->where('embarque_id', 1)->update(['cliente_id' => 7]);
        DB::table('fin_companies_rates')->insert(['ref_pessoa' => 7, 'ref_currency_rate' => 1, 'tax' => 10]);

        $line = app(ArrivalForecastReport::class)('i', '2026-11-01', '2026-11-30')['sea'][0]['groups'][0]['expenses'][0];

        $this->assertEqualsWithDelta(5.5, $line['rate'], 0.001);
    }

    public function test_non_sea_booking_has_a_single_group_titled_by_the_house_bl(): void
    {
        DB::table('embarque')->insert(['embarque_id' => 9, 'booking' => 'AIR1', 'impor_expor' => 'i', 'tipo_embarque' => 'a', 'finalizado' => 'n', 'chegada_prevista' => '2026-11-05', 'house_bl' => 'HAWB9', 'peso_cotacao' => 80, 'volume_cotacao' => 0.6]);
        DB::table('fin_expenses_unities')->insert(['id' => 3, 'name' => 'W/M', 'acronym' => 'W/M']);
        DB::table('fin_expenses')->insert(['id' => 20, 'name' => 'FRETE', 'ref_unity' => 3]);
        DB::table('fin_expenses_values')->insert(['ref_expense' => 20, 'ref_embarque' => 9, 'buying_rate' => 2, 'ref_currency_br' => 1, 'billing' => 'p']);

        $report = app(ArrivalForecastReport::class)('i', '2026-11-01', null);

        $this->assertSame([], $report['sea']);
        $this->assertSame('HAWB9', $report['other'][0]['groups'][0]['title']);
        // volume 0,6 ÷ 0,006 = 100 kg tarifáveis (maior que o peso de 80)
        $this->assertEqualsWithDelta(200.0, $report['other'][0]['groups'][0]['expenses'][0]['converted'], 0.001);
    }

    public function test_page_asks_for_the_period_then_lists_the_booking(): void
    {
        $this->actingAs($this->user('financial', 'manager'));
        $this->sea();

        Livewire::test(ArrivalForecast::class)
            ->assertSee('Informe o período')
            ->fillForm(['tipo' => 'i', 'data_ini' => '2026-11-01', 'data_fim' => '2026-11-30'])
            ->call('send')
            ->assertSeeLivewire(ReportResult::class);

        Livewire::test(ReportResult::class, [
            'action' => ArrivalForecastReport::class,
            'view' => 'filament.pages.arrival-forecast',
            'arguments' => ['i', '2026-11-01', '2026-11-30'],
            'viewData' => ['type' => 'i'],
        ])
            ->assertSee(['IMP1(1)', 'BL-1', 'THC/CTN', '1.020,00']);
    }

    /** IMPO marítima com chegada em 12/11, um BL com dois containers e quatro despesas (uma em dólar). */
    private function sea(): void
    {
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'IMP1', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'finalizado' => 'n', 'chegada_prevista' => '2026-11-12', 'navio_id' => 5]);
        DB::table('bl')->insert(['bl_id' => 1, 'embarque_id' => 1, 'bl' => 'BL-1', 'mbl_number' => 'MBL1']);
        DB::table('container')->insert([
            ['bl_id' => 1, 'tipo_container_id' => 1, 'numero' => 'AAAU1', 'volume' => '10'],
            ['bl_id' => 1, 'tipo_container_id' => 1, 'numero' => 'BBBU2', 'volume' => '10'],
        ]);
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'BRL', 'symbol' => 'R$'], ['id' => 2, 'name' => 'USD', 'symbol' => '$']]);
        DB::table('fin_currencies_rates')->insert(['id' => 1, 'name' => 'USD', 'ref_currency' => 2]);
        DB::table('fin_default_rates')->insert(['ref_currency_rate' => 1, 'default_tax' => 2]);
        DB::table('fin_currencies_rates_values')->insert(['ref_currency_rate' => 1, 'rate' => 5, 'created_by' => 1, 'rate_date' => '2026-11-10']);
        DB::table('fin_expenses_unities')->insert([['id' => 1, 'name' => 'BL', 'acronym' => 'BL'], ['id' => 2, 'name' => 'Container', 'acronym' => 'CTN']]);
        DB::table('fin_expenses')->insert([
            ['id' => 10, 'name' => 'THC', 'ref_unity' => 2],
            ['id' => 11, 'name' => 'DOC', 'ref_unity' => 1],
            ['id' => 192, 'name' => 'DESCONTO', 'ref_unity' => 1],
        ]);
        DB::table('fin_expenses_values')->insert([
            ['ref_expense' => 10, 'ref_embarque' => 1, 'buying_rate' => 100, 'ref_currency_br' => 2, 'billing' => 'p', 'br_min' => null],
            ['ref_expense' => 11, 'ref_embarque' => 1, 'buying_rate' => 10, 'ref_currency_br' => 1, 'billing' => 'p', 'br_min' => 50],
            ['ref_expense' => 192, 'ref_embarque' => 1, 'buying_rate' => 30, 'ref_currency_br' => 1, 'billing' => 'p', 'br_min' => null],
        ]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
