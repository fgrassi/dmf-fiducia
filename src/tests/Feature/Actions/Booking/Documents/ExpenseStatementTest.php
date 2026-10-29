<?php

namespace Tests\Feature\Actions\Booking\Documents;

use App\Actions\Booking\Documents\ExpenseStatement;
use App\Actions\Booking\Documents\ExpenseStatementPdf;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseStatementTest extends TestCase
{
    use LegacySchema;

    private const USD = 2;

    public function test_purchase_lists_each_bl_with_its_own_expenses_and_skips_mail_due_to(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);
        $first = $this->bl($booking, '1001');
        $second = $this->bl($booking, 'HBL2');
        $this->expense($booking, ['ref_expense' => 500, 'ref_bl' => $first, 'buying_rate' => 10, 'ref_currency_br' => self::USD, 'billing' => 'p']);
        $this->expense($booking, ['ref_expense' => 500, 'buying_rate' => 20, 'ref_currency_br' => self::USD, 'billing' => 'c', 'br_min' => 25]);
        $this->expense($booking, ['ref_expense' => 500, 'buying_rate' => 99, 'ref_due_to' => 10]);

        $statement = app(ExpenseStatement::class)($booking, ExpenseStatement::PURCHASE);

        $this->assertSame(
            [
                ['title' => '1001', 'rows' => [['Doc/BL (1001)', 'PP', '1,0000', 'USD 10,00', 'USD 10,00'], ['Doc/BL', 'CC', '1,0000', 'USD 20,00', 'USD 25,00']]],
                ['title' => 'HBL2', 'rows' => [['Doc/BL', 'CC', '1,0000', 'USD 20,00', 'USD 25,00']]],
            ],
            $statement['groups'],
        );
        $this->assertNull($statement['total']);
        $this->assertNotNull($second);
    }

    public function test_import_weight_measure_expense_falls_back_to_the_quotation_factor(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'm', 'peso_cotacao' => 2.5, 'volume_cotacao' => 4]);
        $this->bl($booking, 'HBL1');
        $this->expense($booking, ['ref_expense' => 600, 'buying_rate' => 10, 'ref_currency_br' => self::USD, 'billing' => 'c']);

        $statement = app(ExpenseStatement::class)($booking, ExpenseStatement::PURCHASE);

        $this->assertSame([['Frete LCL/W/M', 'CC', '4,0000', 'USD 10,00', 'USD 40,00']], $statement['groups'][0]['rows']);
    }

    public function test_air_purchase_multiplies_only_weight_measure_expenses_and_needs_a_factor(): void
    {
        $withFactor = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'a', 'peso_cotacao' => 100, 'volume_cotacao' => 0.3]);
        $this->expense($withFactor, ['ref_expense' => 600, 'buying_rate' => 2, 'ref_currency_br' => self::USD, 'billing' => 'c']);
        $this->expense($withFactor, ['ref_expense' => 500, 'buying_rate' => 7, 'ref_currency_br' => self::USD, 'billing' => 'c']);
        $without = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'a']);

        $statement = app(ExpenseStatement::class)($withFactor, ExpenseStatement::PURCHASE);

        $this->assertSame(
            [['Frete LCL/W/M', 'CC', '100,000', 'USD 2,00', 'USD 200,000'], ['Doc/BL', 'CC', '100,000', 'USD 7,00', 'USD 7,000']],
            $statement['groups'][0]['rows'],
        );
        $this->assertSame('É necessário que o fator seja maior que 0', app(ExpenseStatement::class)($without, ExpenseStatement::PURCHASE)['message']);
    }

    public function test_sale_converts_to_reais_filters_the_billing_and_totals(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'm']);
        $this->bl($booking, 'HBL1');
        $this->quote(self::USD, 5.5);
        $this->expense($booking, ['ref_expense' => 500, 'selling_rate' => 10, 'ref_currency_sr' => self::USD, 'billing' => 'c']);
        $this->expense($booking, ['ref_expense' => 500, 'selling_rate' => 11, 'ref_currency_sr' => self::USD, 'billing' => 'p']);
        $this->expense($booking, ['ref_expense' => 500, 'selling_rate' => 12, 'ref_currency_sr' => self::USD, 'billing' => 'c', 'all_in' => '1']);
        $this->expense($booking, ['ref_expense' => 500, 'selling_rate' => 0, 'ref_currency_sr' => self::USD, 'billing' => 'c']);

        $statement = app(ExpenseStatement::class)($booking, ExpenseStatement::SALE);

        $this->assertSame([['Doc/BL', 'CC', 'USD 10,00', '1,0000', '5,5000', 'R$ 55,00']], $statement['groups'][0]['rows']);
        $this->assertSame('R$ 55,00', $statement['total']);
        $this->assertStringStartsWith('*Este documento é uma previsão, utilizando-se como base de conversão a cotação das moedas estrangeiras com data de 29/10/2026.', $statement['footnote']);
        $this->assertStringContainsString('confirmação de chegada de sua carga no porto/aeroporto de destino', $statement['footnote']);
    }

    public function test_sale_with_minimum_keeps_the_legacy_mismatch_between_the_line_and_the_total(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);
        $this->bl($booking, 'HBL1', ['gross_weight' => '2000', 'volume' => '1']);
        $this->quote(self::USD, 5);
        $this->expense($booking, ['ref_expense' => 600, 'selling_rate' => 10, 'sr_min' => 100, 'ref_currency_sr' => self::USD, 'billing' => 'p']);

        $statement = app(ExpenseStatement::class)($booking, ExpenseStatement::SALE);

        $this->assertSame('R$ 500,00', $statement['groups'][0]['rows'][0][5]);
        $this->assertSame('R$ 1.000,00', $statement['total']);
    }

    public function test_sale_without_a_quotation_names_the_currency(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);
        $this->bl($booking, 'HBL1');
        $this->expense($booking, ['ref_expense' => 500, 'selling_rate' => 10, 'ref_currency_sr' => self::USD, 'billing' => 'p']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não há cotação para a moeda USD');

        app(ExpenseStatement::class)($booking, ExpenseStatement::SALE);
    }

    public function test_documentation_shows_selling_in_the_original_currency_with_minimum(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);
        $this->bl($booking, 'HBL1');
        $this->expense($booking, ['ref_expense' => 500, 'selling_rate' => 10, 'sr_min' => 30, 'ref_currency_sr' => self::USD, 'billing' => 'p']);
        $this->expense($booking, ['ref_expense' => 500, 'selling_rate' => 10, 'ref_currency_sr' => self::USD, 'billing' => 'p', 'all_in' => '1']);

        $statement = app(ExpenseStatement::class)($booking, ExpenseStatement::DOCUMENTATION);

        $this->assertSame([['Doc/BL', 'PP', '1,0000', 'USD 10,00', 'USD 30,00']], $statement['groups'][0]['rows']);
    }

    public function test_documentation_only_exists_for_maritime_bookings(): void
    {
        $this->expectException(DomainException::class);

        app(ExpenseStatement::class)($this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'a']), ExpenseStatement::DOCUMENTATION);
    }

    #[DataProvider('kinds')]
    public function test_renders_a_pdf_for_each_kind(string $kind): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm', 'booking' => 'EXP1']);
        $this->bl($booking, 'HBL1');
        $this->quote(self::USD, 5);
        $this->expense($booking, ['ref_expense' => 500, 'buying_rate' => 10, 'selling_rate' => 10, 'ref_currency_br' => self::USD, 'ref_currency_sr' => self::USD, 'billing' => 'p']);

        $pdf = app(ExpenseStatementPdf::class)->render($booking, $kind)->Output('', 'S');

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    /** @return array<string, array{0: string}> */
    public static function kinds(): array
    {
        return [
            'compra' => [ExpenseStatement::PURCHASE],
            'despesa' => [ExpenseStatement::SALE],
            'documentação' => [ExpenseStatement::DOCUMENTATION],
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        DB::table('cmn_currencies')->insertOrIgnore([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']]);
        DB::table('fin_expenses_unities')->insertOrIgnore([['id' => 1, 'name' => 'BL', 'acronym' => 'BL'], ['id' => 3, 'name' => 'W/M', 'acronym' => 'W/M']]);
        DB::table('fin_expenses')->insertOrIgnore([['id' => 500, 'name' => 'Doc', 'ref_unity' => 1], ['id' => 600, 'name' => 'Frete LCL', 'ref_unity' => 3]]);

        return Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'BKG'], ...$attributes]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function bl(Embarque $booking, string $name, array $attributes = []): int
    {
        return DB::table('bl')->insertGetId(['embarque_id' => $booking->getKey(), 'bl' => $name, ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(Embarque $booking, array $attributes): int
    {
        return DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $booking->getKey(), ...$attributes]);
    }

    private function quote(int $currency, float $value): void
    {
        $rate = DB::table('fin_currencies_rates')->insertGetId(['name' => 'PTAX', 'ref_currency' => $currency]);
        DB::table('fin_default_rates')->insert(['ref_currency_rate' => $rate, 'default_tax' => 0]);
        DB::table('fin_currencies_rates_values')->insert(['ref_currency_rate' => $rate, 'rate' => $value, 'rate_date' => '2026-10-28', 'created_by' => 1]);
    }
}
