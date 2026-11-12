<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\ExpenseSummary;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\ExpensesRelationManager;
use App\Models\Embarque;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseSummaryTest extends TestCase
{
    use LegacySchema;

    private function seedExpenses(): Embarque
    {
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']]);
        DB::table('fin_expenses_values_due_to')->insert([['id' => 7, 'name' => 'Agente'], ['id' => 8, 'name' => 'DMF']]);
        DB::table('fin_currencies_rates')->insert(['id' => 3, 'ref_currency' => 2, 'name' => 'Taxa Diária']);
        DB::table('fin_currencies_rates_values')->insert([
            ['ref_currency_rate' => 3, 'rate' => 5.0, 'rate_date' => '2026-11-02', 'created_by' => 1],
            ['ref_currency_rate' => 3, 'rate' => 5.5, 'rate_date' => '2026-11-03', 'created_by' => 1],
        ]);
        $booking = Embarque::forceCreate(['embarque_id' => 1, 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'embarque_obs' => 'Cuidado com a carga']);

        $expense = fn (array $values) => DB::table('fin_expenses_values')->insert($values + ['ref_embarque' => 1, 'created_at' => '2026-11-01']);
        $expense(['ref_due_to' => 7, 'ref_currency_br' => 2, 'buying_rate' => 100, 'ref_currency_sr' => 2, 'selling_rate' => 150]);
        $expense(['ref_due_to' => 7, 'ref_currency_br' => 2, 'buying_rate' => 50, 'ref_currency_sr' => 2, 'selling_rate' => 50]);
        $expense(['ref_due_to' => 8, 'ref_currency_br' => 1, 'buying_rate' => 75, 'ref_currency_sr' => 2, 'selling_rate' => 10]); // moedas diferentes: dois baldes
        $expense(['ref_due_to' => null, 'ref_currency_br' => 1, 'buying_rate' => 999, 'ref_currency_sr' => 1, 'selling_rate' => 0]); // sem Due To: fora

        return $booking;
    }

    public function test_balances_per_due_to_and_currency_in_reais_at_the_latest_rate(): void
    {
        $summary = app(ExpenseSummary::class)($this->seedExpenses());

        $this->assertSame(
            [['Agente', 'US$', 150.0, 200.0, 50.0, 275.0], ['DMF', 'R$', 75.0, 0.0, -75.0, -75.0], ['DMF', 'US$', 0.0, 10.0, 10.0, 55.0]],
            array_map(fn ($r) => [$r['to'], $r['currency'], $r['cost'], $r['sale'], $r['balance'], $r['balance_brl']], $summary['rows']),
        );
        $this->assertEqualsWithDelta(255.0, $summary['total'], 0.001);
        $this->assertTrue($summary['complete']);
    }

    public function test_expenses_tab_shows_the_summary_and_the_booking_notes(): void
    {
        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'booking'])));
        $booking = $this->seedExpenses();

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertSee('Resumo de Custos e Receitas')
            ->assertSee('Total do saldo em R$')
            ->assertSee('Cuidado com a carga');
    }
}
