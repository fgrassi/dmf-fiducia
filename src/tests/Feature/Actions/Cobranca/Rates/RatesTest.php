<?php

namespace Tests\Feature\Actions\Cobranca\Rates;

use App\Actions\Cobranca\Rates\DeleteCustomRate;
use App\Actions\Cobranca\Rates\SaveCustomRate;
use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class RatesTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-27 10:00');
        $this->usdRate();
    }

    public function test_brl_is_always_one_and_the_default_table_adds_its_percentage(): void
    {
        $charge = $this->charge();

        $this->assertSame(1.0, app(ChargeRates::class)->rate(1, 1, $charge));
        $this->assertSame(5.1, app(ChargeRates::class)->rate(1, 2, $charge));
    }

    public function test_company_table_wins_over_the_default_and_the_negotiated_rate_wins_over_both(): void
    {
        $charge = $this->charge();
        DB::table('fin_currencies_rates')->insert(['id' => 2, 'name' => 'USD ACME', 'ref_currency' => 2]);
        DB::table('fin_currencies_rates_values')->insert(['ref_currency_rate' => 2, 'rate' => 4.0, 'created_by' => 1, 'rate_date' => '2026-10-27']);
        DB::table('fin_companies_rates')->insert(['ref_pessoa' => 77, 'ref_currency_rate' => 2, 'tax' => 1]);

        $this->assertSame(4.04, app(ChargeRates::class)->rate(77, 2, $charge));

        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $charge->id, 'note' => 'n', 'created_at' => '2026-10-20', 'created_by' => 1, 'custom_rate' => 6.25]);
        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $charge->id, 'note' => 'n', 'created_at' => '2026-10-21', 'created_by' => 1, 'custom_rate' => 6.5]);

        $this->assertSame(6.5, app(ChargeRates::class)->rate(77, 2, $charge));
    }

    public function test_quote_date_moves_to_the_next_business_day_after_the_cutoff(): void
    {
        $rates = app(ChargeRates::class);

        $this->assertSame('2026-10-27', $rates->quoteDate());

        $this->travelTo('2026-10-27 16:00');
        $this->assertSame('2026-10-28', $rates->quoteDate());

        $this->travelTo('2026-10-30 17:30'); // sexta
        $this->assertSame('2026-11-02', $rates->quoteDate());
    }

    public function test_client_id_falls_back_to_exporter_or_importer_by_direction(): void
    {
        $embarque = fn (array $a) => (new Embarque)->forceFill($a);

        $this->assertSame(5, ChargeRates::clientId($embarque(['cliente_id' => 5, 'impor_expor' => 'e'])));
        $this->assertSame(6, ChargeRates::clientId($embarque(['impor_expor' => 'e', 'pessoa_id_cliente' => 6])));
        $this->assertSame(7, ChargeRates::clientId($embarque(['impor_expor' => 'i', 'pessoa_id_importador' => 7])));
        $this->assertSame(-1, ChargeRates::clientId($embarque(['impor_expor' => 'x'])));
    }

    public function test_up_to_date_ignores_brl_and_negotiated_currencies_and_warns_finance_otherwise(): void
    {
        $charge = $this->charge(booking: ['pessoa_id_cliente' => 5]);
        $this->item($charge, 100, currency: 1);
        $this->item($charge, 100, currency: 2, expense: 501);
        DB::table('fin_currencies_rates_values')->delete();

        $this->assertFalse(app(ChargeRates::class)->upToDate($charge));
        $this->assertSame(['DMF Logistics: AVISO: Cotacao padrão desatualizada para a moeda USD'], $this->sentSubjects()->all());

        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $charge->id, 'note' => 'n', 'created_at' => '2026-10-20', 'created_by' => 1, 'custom_rate' => 6]);
        $this->assertTrue(app(ChargeRates::class)->upToDate($charge));
    }

    public function test_save_custom_rate_accepts_only_currencies_of_the_items(): void
    {
        $charge = $this->charge();
        $this->item($charge, 100, currency: 2);
        $this->item($charge, 100, currency: 1, expense: 501);

        $this->assertSame([2 => 'USD'], SaveCustomRate::currencyOptions($charge));

        $rate = app(SaveCustomRate::class)($charge, 2, '5,4321', 'acordo comercial', 9);

        $this->assertSame([5.4321, 'acordo comercial', 9], [(float) $rate->custom_rate, $rate->note, (int) $rate->created_by]);
        $this->assertRefused(fn () => app(SaveCustomRate::class)($charge, 1, '5', 'n', 9), 'Moeda inválida: a cobrança não possui itens nessa moeda.');
        $this->assertRefused(fn () => app(SaveCustomRate::class)($charge, 2, 'abc', 'n', 9), 'Valor negociado inválido.');
        $this->assertRefused(fn () => app(SaveCustomRate::class)($charge, 2, '5', '  ', 9), 'Todos os campos precisam estar preenchidos!');
        $this->assertSame(1, DB::table('fin_custom_currency_rate')->count());
    }

    public function test_delete_custom_rate_only_touches_rates_of_that_charge(): void
    {
        $charge = $this->charge();
        $other = $this->charge(booking: ['embarque_id' => 11]);
        $mine = DB::table('fin_custom_currency_rate')->insertGetId(['ref_currency' => 2, 'ref_charge' => $charge->id, 'note' => 'n', 'created_at' => '2026-10-20', 'created_by' => 1, 'custom_rate' => 6]);
        $theirs = DB::table('fin_custom_currency_rate')->insertGetId(['ref_currency' => 2, 'ref_charge' => $other->id, 'note' => 'n', 'created_at' => '2026-10-20', 'created_by' => 1, 'custom_rate' => 6]);

        app(DeleteCustomRate::class)($charge, $theirs);
        $this->assertSame(2, DB::table('fin_custom_currency_rate')->count());

        app(DeleteCustomRate::class)($charge, $mine);
        $this->assertSame([$theirs], DB::table('fin_custom_currency_rate')->pluck('id')->all());
    }

    private function assertRefused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
