<?php

namespace Tests\Feature\Actions\CurrencyRates;

use App\Actions\CurrencyRates\SaveRateValue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SaveRateValueTest extends TestCase
{
    use LegacySchema;

    public function test_base_rate_propagates_to_linked_rates_and_updates_in_place(): void
    {
        $base = DB::table('fin_currencies_rates')->insertGetId(['name' => 'PTAX', 'ref_currency' => 1]);
        $linked = DB::table('fin_currencies_rates')->insertGetId(['name' => 'Comercial', 'ref_currency' => 1, 'ref_currency_rate' => $base, 'fator' => 10]);
        $payment = DB::table('fin_currencies_rates_armador_payment')->insertGetId(['ref_armador' => 1, 'ref_currency' => 1, 'type' => 'i', 'fin_currencies_rates_id' => $base, 'fator' => -5]);

        (new SaveRateValue)(SaveRateValue::CURRENCY, $base, 5.0, userId: 7);
        (new SaveRateValue)(SaveRateValue::CURRENCY, $base, 6.0, userId: 7);

        $this->assertEquals([6.0], DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $base)->pluck('rate')->all());
        $this->assertEqualsWithDelta(6.6, DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $linked)->sole()->rate, 1e-9);
        $this->assertEqualsWithDelta(5.7, DB::table('fin_currencies_rates_armador_payment_values')->where('ref_currency_rate_armador', $payment)->sole()->rate, 1e-9);
        $this->assertSame(7, (int) DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $base)->value('updated_by'));
    }

    public function test_carrier_rate_does_not_accept_future_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SaveRateValue)(SaveRateValue::CARRIER, 1, 5.0, now()->addDay()->toDateString());
    }

    public function test_carrier_rate_creates_value_on_given_date(): void
    {
        (new SaveRateValue)(SaveRateValue::CARRIER, 3, 4.25, '2026-10-01', 9);

        $value = DB::table('fin_currencies_rates_armador_values')->sole();
        $this->assertSame('2026-10-01', $value->rate_date);
        $this->assertSame(9, (int) $value->created_by);
    }
}
