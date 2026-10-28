<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CurrencyRates\Pages\ManageCurrencyRates;
use App\Models\FinCurrenciesRates;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CurrencyRateResourceTest extends TestCase
{
    use LegacySchema;

    public function test_launching_base_value_updates_linked_rate_and_shows_latest(): void
    {
        $user = $this->user('financial');
        $this->actingAs($user);
        $base = FinCurrenciesRates::create(['name' => 'PTAX', 'ref_currency' => 1]);
        $linked = FinCurrenciesRates::create(['name' => 'Comercial', 'ref_currency' => 1, 'ref_currency_rate' => $base->id, 'fator' => 2]);

        Livewire::test(ManageCurrencyRates::class)
            ->callTableAction('launchValue', $base, ['rate' => '5.5'])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsWithDelta(5.61, DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $linked->id)->sole()->rate, 1e-9);
        $this->assertSame($user->getKey(), (int) DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $base->id)->value('created_by'));

        Livewire::test(ManageCurrencyRates::class)
            ->assertTableColumnFormattedStateSet('latest_rate', '5,5000', $base);
    }

    public function test_rate_with_links_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('financial'));
        $base = FinCurrenciesRates::create(['name' => 'PTAX', 'ref_currency' => 1]);
        FinCurrenciesRates::create(['name' => 'Comercial', 'ref_currency' => 1, 'ref_currency_rate' => $base->id]);

        Livewire::test(ManageCurrencyRates::class)
            ->assertTableActionHidden(DeleteAction::class, $base);
    }

    public function test_requires_financial_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageCurrencyRates::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
