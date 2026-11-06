<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\DailyRatesReport;
use App\Filament\Pages\DailyRates;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DailyRatesTest extends TestCase
{
    use LegacySchema;

    public function test_only_financial_opens_the_page(): void
    {
        $this->actingAs($this->user('manager'))->get(DailyRates::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(DailyRates::getUrl())->assertOk();
    }

    public function test_matrix_has_one_row_per_day_newest_first_with_the_last_value_of_each_day(): void
    {
        $this->seedRates();

        $report = app(DailyRatesReport::class)(['data_ini' => '2026-10-01', 'data_fim' => '2026-10-03']);

        $this->assertSame([
            ['name' => 'PTAX', 'currency' => 'USD ($)'],
            ['name' => 'Maersk', 'currency' => 'USD ($)'],
        ], $report['columns']);
        $this->assertSame(['2026-10-03', '2026-10-02', '2026-10-01'], array_column($report['rows'], 'date'));
        $this->assertSame([null, null], $report['rows'][0]['values']);
        $this->assertSame([5.5, 5.4], $report['rows'][1]['values']);
        $this->assertSame([5.0, null], $report['rows'][2]['values']);
    }

    public function test_page_is_queried_on_open_and_formats_values_with_four_decimals(): void
    {
        $this->actingAs($this->user('financial'));
        $this->seedRates(now()->toDateString());

        Livewire::test(DailyRates::class)
            ->assertSee(['PTAX', 'Maersk', 'USD ($)', '5,5000', '5,4000'])
            ->fillForm(['data_ini' => '2026-10-03', 'data_fim' => '2026-10-01'])
            ->call('send')
            ->assertHasFormErrors(['data_fim']);
    }

    private function seedRates(string $day = '2026-10-02'): void
    {
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'USD', 'symbol' => '$']);
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'Maersk']);
        DB::table('fin_currencies_rates')->insert(['id' => 1, 'name' => 'PTAX', 'ref_currency' => 2]);
        DB::table('fin_currencies_rates_armador')->insert(['id' => 1, 'ref_armador' => 1, 'ref_currency' => 2]);

        DB::table('fin_currencies_rates_values')->insert([
            ['ref_currency_rate' => 1, 'rate' => 5.0, 'created_by' => 1, 'created_at' => '2026-10-01', 'rate_date' => '2026-10-01'],
            ['ref_currency_rate' => 1, 'rate' => 5.1, 'created_by' => 1, 'created_at' => $day, 'rate_date' => $day],
            ['ref_currency_rate' => 1, 'rate' => 5.5, 'created_by' => 1, 'created_at' => $day, 'rate_date' => '2026-09-30'],
        ]);
        DB::table('fin_currencies_rates_armador_values')->insert(['ref_currency_rate_armador' => 1, 'rate' => 5.4, 'created_by' => 1, 'created_at' => $day, 'rate_date' => $day]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
