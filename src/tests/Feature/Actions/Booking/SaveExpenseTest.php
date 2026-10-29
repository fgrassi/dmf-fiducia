<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\SaveExpense;
use App\Models\Embarque;
use App\Models\FinExpensesValues;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SaveExpenseTest extends TestCase
{
    use LegacySchema;

    private const USD = 2;

    private const THC = 12;

    public function test_creates_the_expense_and_sends_the_booking_back_to_billing_and_payment(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = $this->booking(['ctr_desp' => 0, 'ctr_pgto' => 0, 'ctr_pgto_outros' => 0]);
        $this->expenseType(500, 'Documentação');

        $expense = app(SaveExpense::class)($booking, $this->data(['ref_expense' => 500, 'buying_rate' => 100, 'selling_rate' => '150.5', 'ctrl' => true]), null, 7);

        $this->assertModelExists($expense);
        $this->assertSame(
            [$booking->getKey(), 500, 100.0, 150.5, 'p', '1', '0', 7, '2026-10-29'],
            [$expense->ref_embarque, $expense->ref_expense, $expense->buying_rate, $expense->selling_rate, $expense->billing, $expense->ctrl, $expense->ctrl2, $expense->created_by, $expense->created_at],
        );
        $this->assertSame([1, 1, 1], [(int) $booking->fresh()->ctr_desp, (int) $booking->fresh()->ctr_pgto, (int) $booking->fresh()->ctr_pgto_outros]);
        $this->assertSame(['Atualizado: Despesa', 'u', '2026-10-29', 7], [DB::table('rastreamento')->value('assunto'), DB::table('rastreamento')->value('evento'), DB::table('rastreamento')->value('created_at'), (int) DB::table('rastreamento')->value('created_by')]);
        $this->assertStringContainsString('<p>Despesa: Documentação</p>', DB::table('rastreamento')->value('historico'));
        $this->assertStringContainsString('<p>B/R: USD100,00</p>', DB::table('rastreamento')->value('historico'));
    }

    public function test_edit_updates_the_expense_and_records_only_what_changed(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = $this->booking();
        $this->expenseType(500, 'Documentação');
        $expense = app(SaveExpense::class)($booking, $this->data(['ref_expense' => 500, 'buying_rate' => 100, 'selling_rate' => 150]), null, 7);
        DB::table('rastreamento')->delete();

        app(SaveExpense::class)($booking, $this->data(['ref_expense' => 500, 'buying_rate' => 120, 'selling_rate' => 150, 'billing' => 'c']), $expense->fresh(), 8);

        $expense->refresh();
        $this->assertSame([120.0, 'c', 8, '2026-10-29', 7], [$expense->buying_rate, $expense->billing, $expense->updated_by, $expense->updated_at, $expense->created_by]);
        $history = DB::table('rastreamento')->value('historico');
        $this->assertStringContainsString('<p>Valor Compra: DE: 100,00 PARA: 120,00</p>', $history);
        $this->assertStringContainsString('<p>Tipo: DE: PP PARA: CC</p>', $history);
        $this->assertStringNotContainsString('Valor Venda', $history);
        $this->assertSame(1, DB::table('fin_expenses_values')->count());
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('missingFields')]
    public function test_rejects_missing_required_fields_without_saving(array $overrides, string $message): void
    {
        $booking = $this->booking();

        $this->assertRejects($booking, $this->data($overrides), $message);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function missingFields(): array
    {
        return [
            'despesa' => [['ref_expense' => null], 'É necessário selecionar uma despesa!'],
            'tipo' => [['billing' => ' '], 'É necessário selecionar um tipo de despesa!'],
            'due to' => [['ref_due_to' => null], 'É necessário informar o Due To!'],
            'moeda de custo' => [['ref_currency_br' => ''], 'É necessário informar a Moeda de Custo!'],
            'custo' => [['buying_rate' => ''], 'É necessário informar o valor do Custo!'],
            'moeda de venda' => [['ref_currency_sr' => null], 'É necessário informar a Moeda de Venda!'],
            'venda' => [['selling_rate' => null], 'É necessário informar o valor da Venda!'],
        ];
    }

    public function test_air_import_does_not_require_selling_and_derives_it_from_profit(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'a']);

        $expense = app(SaveExpense::class)($booking, $this->data(['buying_rate' => 100, 'selling_rate' => null, 'ref_currency_sr' => null, 'profit' => 25, 'ref_currency_br' => self::USD]), null, 7);

        $this->assertSame([125.0, self::USD], [$expense->selling_rate, $expense->ref_currency_sr]);
    }

    public function test_air_booking_rejects_selling_by_value_and_percentage_together(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'a']);

        $this->assertRejects($booking, $this->data(['selling_rate' => 150, 'selling_rate_perc' => 10]), 'Preencha a venda em valor OU percentual!');
    }

    public function test_international_freight_cannot_be_in_reais(): void
    {
        $booking = $this->booking();

        $this->assertRejects($booking, $this->data(['ref_expense' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => self::USD]), 'O Frete Internacional não pode ser lançado em R$!');
    }

    public function test_import_fees_must_be_in_reais_but_exports_are_free(): void
    {
        $import = $this->booking(['impor_expor' => 'i']);
        $export = $this->booking(['impor_expor' => 'e']);

        $this->assertRejects($import, $this->data(['ref_expense' => self::THC, 'ref_currency_br' => 1, 'ref_currency_sr' => self::USD]), 'Essa taxa deve ser lançada com a moeda de Compra e Venda em R$!');
        $expense = app(SaveExpense::class)($export, $this->data(['ref_expense' => self::THC, 'ref_currency_br' => self::USD, 'ref_currency_sr' => self::USD]), null, 7);
        $this->assertModelExists($expense);
    }

    public function test_weight_measure_expense_needs_both_minimums(): void
    {
        $booking = $this->booking();
        DB::table('fin_expenses')->insert(['id' => 600, 'name' => 'Frete LCL', 'ref_unity' => 3]);

        $this->assertRejects($booking, $this->data(['ref_expense' => 600, 'br_min' => 10]), 'Para despesas do tipo W/M é preciso informar a Venda Mínima!');
        $this->assertRejects($booking, $this->data(['ref_expense' => 600, 'sr_min' => 10]), 'Para despesas do tipo W/M é preciso informar o Custo Mínimo!');
        $this->assertModelExists(app(SaveExpense::class)($booking, $this->data(['ref_expense' => 600, 'br_min' => 10, 'sr_min' => 12]), null, 7));
    }

    public function test_arbitrary_fee_needs_a_freight_with_the_same_selling_currency(): void
    {
        $booking = $this->booking();
        $this->freight($booking, 1, self::USD);

        $this->assertRejects($booking, $this->data(['ref_expense' => 33, 'ref_currency_sr' => 3]), 'Não é possível cadastrar esta despesa. É necessário ter um Frete com o mesmo tipo de cobrança e moeda de venda cadastrado!');
        $this->assertRejects($booking, $this->data(['ref_expense' => 33, 'ref_currency_sr' => self::USD, 'ref_equipment' => 5]), 'Não é possível cadastrar esta despesa. É necessário ter um Frete com o mesmo tipo de cobrança, moeda de venda e equipamento cadastrado!');
        $this->assertModelExists(app(SaveExpense::class)($booking, $this->data(['ref_expense' => 33, 'ref_currency_sr' => self::USD]), null, 7));
    }

    public function test_expense_1916_is_forced_to_pp_dmf_without_selling_whatever_is_sent(): void
    {
        $booking = $this->booking();

        $expense = app(SaveExpense::class)($booking, $this->data(['ref_expense' => 1916, 'billing' => 'c', 'ref_due_to' => 3, 'selling_rate' => 90, 'ref_currency_sr' => self::USD, 'sr_min' => 5, 'agreement' => true]), null, 7);

        $this->assertSame(['p', 7, '1', '1', null, null, null, null], [$expense->billing, $expense->ref_due_to, $expense->ctrl3, $expense->all_in, $expense->selling_rate, $expense->ref_currency_sr, $expense->sr_min, $expense->agreement]);
    }

    public function test_alerts_finance_only_for_imports_with_notes_and_an_expense_of_group_12(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'booking' => 'IMP9']);
        DB::table('grupo_despesa_despesa')->insert(['grupo_despesa_id' => 12, 'despesa_id' => 500]);
        $this->expenseType(500, 'Documentação');
        $data = $this->data(['ref_expense' => 500]);

        app(SaveExpense::class)($booking, $data, null, 7);
        $this->assertSame([], $this->sentSubjects()->all());

        DB::table('fin_debit_notes')->insert(['ref_embarque' => $booking->getKey(), 'ref_agente' => 1, 'created_at' => '2026-10-01', 'created_by' => 1]);
        app(SaveExpense::class)($booking, $data, null, 7);

        $this->assertSame(['[FINANCEIRO] Alerta de Alterações: IMP9'], $this->sentSubjects()->all());
    }

    /** @param  array<string, mixed>  $data */
    private function assertRejects(Embarque $booking, array $data, string $message): void
    {
        $before = FinExpensesValues::count();

        try {
            app(SaveExpense::class)($booking, $data, null, 7);
            $this->fail('A despesa deveria ter sido recusada.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($before, FinExpensesValues::count());
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function data(array $overrides = []): array
    {
        return [
            'ref_expense' => 500,
            'billing' => 'p',
            'ref_due_to' => 7,
            'ref_currency_br' => self::USD,
            'buying_rate' => 100,
            'ref_currency_sr' => self::USD,
            'selling_rate' => 150,
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        DB::table('cmn_currencies')->insertOrIgnore([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD'], ['id' => 3, 'name' => 'Euro', 'symbol' => 'EUR']]);

        return Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'BKG', 'impor_expor' => 'e', 'tipo_embarque' => 'm'], ...$attributes]));
    }

    private function expenseType(int $id, string $name): void
    {
        DB::table('fin_expenses')->insertOrIgnore(['id' => $id, 'name' => $name]);
    }

    private function freight(Embarque $booking, int $expense, int $currency): void
    {
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $booking->getKey(), 'ref_expense' => $expense, 'ref_currency_sr' => $currency]);
    }
}
