<?php

namespace App\Actions\Booking;

use App\Models\FinExpensesValues;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * updateEmbarqueExpenses act=3 (legado): exclui a despesa da reserva e registra "Apagado: Despesa".
 * Bloqueia se houver Arbitrary Fee ligada ao frete, ou se ela já entrou em cobrança, ordem de pagamento ou nota.
 */
class DeleteExpense
{
    /** tabela de itens => documento, na ordem em que o legado checa */
    private const USED_IN = [
        'fin_charges_items' => 'uma Cobrança',
        'fin_payment_order_items' => 'uma Ordem de Pagamento',
        'fin_credit_note_items' => 'uma Nota de Crédito',
        'fin_debit_note_items' => 'uma Nota de Débito',
    ];

    public function __construct(private ExpenseTracking $tracking) {}

    /** @throws DomainException */
    public function __invoke(FinExpensesValues $expense, int $userId): void
    {
        $this->ensureCanDelete($expense);

        DB::transaction(function () use ($expense, $userId) {
            ($this->tracking)($expense->getAttributes(), 'd', null, $userId);
            $expense->delete();
        });
    }

    /** @throws DomainException */
    public function ensureCanDelete(FinExpensesValues $expense): void
    {
        $fee = SaveExpense::arbitraryFeeOf((int) $expense->ref_expense);

        if ($fee && FinExpensesValues::where('ref_embarque', $expense->ref_embarque)->where('ref_expense', $fee)->exists()) {
            throw new DomainException('Não é possível remover esta despesa pois há uma despesa de Arbitrary Fee vinculada a ela.');
        }

        foreach (self::USED_IN as $table => $document) {
            if (DB::table($table)->where('ref_expense', $expense->getKey())->exists()) {
                throw new DomainException("Não foi possível excluir essa despesa. Existe {$document} associada a ela.");
            }
        }
    }
}
