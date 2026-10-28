<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CarrierPaymentRates\Pages\ManageCarrierPaymentRates;
use App\Models\FinCurrenciesRatesArmadorPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CarrierPaymentRateResourceTest extends TestCase
{
    use LegacySchema;

    public function test_name_is_carrier_and_type(): void
    {
        $this->actingAs($this->user('financial'));
        $carrier = DB::table('armador')->insertGetId(['nome' => 'Maersk']);
        $currency = DB::table('cmn_currencies')->insertGetId(['name' => 'Dólar', 'symbol' => 'USD']);

        Livewire::test(ManageCarrierPaymentRates::class)
            ->callAction('create', ['ref_armador' => $carrier, 'ref_currency' => $currency, 'type' => 'i'])
            ->assertHasNoFormErrors();

        $this->assertSame('Maersk (Import)', FinCurrenciesRatesArmadorPayment::sole()->name);
    }

    public function test_future_date_is_refused_when_launching_any_value(): void
    {
        $this->actingAs($this->user('financial'));
        $carrier = DB::table('armador')->insertGetId(['nome' => 'Maersk']);
        $rate = FinCurrenciesRatesArmadorPayment::create(['ref_armador' => $carrier, 'ref_currency' => 1, 'type' => 'e']);

        Livewire::test(ManageCarrierPaymentRates::class)
            ->callAction('launchAnyValue', ['rate_id' => $rate->id, 'rate' => '5', 'rate_date' => now()->addDay()->toDateString()])
            ->assertHasActionErrors(['rate_date']);

        $this->assertSame(0, DB::table('fin_currencies_rates_armador_payment_values')->count());
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
