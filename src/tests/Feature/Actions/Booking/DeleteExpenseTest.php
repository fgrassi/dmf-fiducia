<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\DeleteExpense;
use App\Models\FinExpensesValues;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DeleteExpenseTest extends TestCase
{
    use LegacySchema;

    public function test_deletes_a_free_expense_and_records_it_in_the_history(): void
    {
        $this->travelTo('2026-10-29 10:00');
        DB::table('fin_expenses')->insert(['id' => 500, 'name' => 'Documentação']);
        $expense = $this->expense(['ref_expense' => 500, 'billing' => 'p']);

        app(DeleteExpense::class)($expense, 7);

        $this->assertModelMissing($expense);
        $this->assertSame(['Apagado: Despesa', 'd', 10, 7], [DB::table('rastreamento')->value('assunto'), DB::table('rastreamento')->value('evento'), (int) DB::table('rastreamento')->value('ref_embarque'), (int) DB::table('rastreamento')->value('created_by')]);
        $this->assertStringContainsString('<p>Despesa: Documentação</p>', DB::table('rastreamento')->value('historico'));
    }

    #[DataProvider('documents')]
    public function test_blocks_an_expense_already_used_in_a_document(string $table, array $row, string $message): void
    {
        $expense = $this->expense();
        DB::table($table)->insert([...$row, 'ref_expense' => $expense->getKey()]);

        $this->assertBlocked($expense, $message);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
    public static function documents(): array
    {
        return [
            'cobrança' => ['fin_charges_items', ['ref_charge' => 1, 'ref_currency' => 1, 'created_at' => '2026-10-01'], 'Não foi possível excluir essa despesa. Existe uma Cobrança associada a ela.'],
            'ordem de pagamento' => ['fin_payment_order_items', ['ref_payment_order' => 1, 'ref_bl' => 1, 'ref_currency' => 1, 'created_at' => '2026-10-01'], 'Não foi possível excluir essa despesa. Existe uma Ordem de Pagamento associada a ela.'],
            'nota de crédito' => ['fin_credit_note_items', ['ref_credit_note' => 1], 'Não foi possível excluir essa despesa. Existe uma Nota de Crédito associada a ela.'],
            'nota de débito' => ['fin_debit_note_items', ['ref_debit_note' => 1], 'Não foi possível excluir essa despesa. Existe uma Nota de Débito associada a ela.'],
        ];
    }

    public function test_blocks_a_freight_that_still_has_its_arbitrary_fee(): void
    {
        $freight = $this->expense(['ref_expense' => 1]);
        $fee = $this->expense(['ref_expense' => 33]);

        $this->assertBlocked($freight, 'Não é possível remover esta despesa pois há uma despesa de Arbitrary Fee vinculada a ela.');

        app(DeleteExpense::class)($fee, 7);
        app(DeleteExpense::class)($freight, 7);

        $this->assertSame(0, FinExpensesValues::count());
    }

    private function assertBlocked(FinExpensesValues $expense, string $message): void
    {
        try {
            app(DeleteExpense::class)($expense, 7);
            $this->fail('A despesa deveria ter sido bloqueada.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertModelExists($expense);
            $this->assertSame(0, DB::table('rastreamento')->count());
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(array $attributes = []): FinExpensesValues
    {
        return FinExpensesValues::find(DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, ...$attributes]));
    }
}
