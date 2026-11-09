<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use Illuminate\Support\Facades\DB;

/**
 * Cálculo dos valores da nota a partir dos itens (executeGeraDebitNote / AddDebitNoteItem / RemoveDebitNoteItem e as três
 * do crédito, que repetiam a mesma conta): lucro = Σ (venda − compra) × fator; se há despesa "HANDLING DMF" o lucro (menos a
 * comissão, no débito) é todo da DMF, senão é dividido ao meio com o agente. Handling e comissão de origem são digitados à mão
 * e não mudam aqui. Soma valores sem converter moeda, como o legado.
 */
class RecalculateNote
{
    private const HANDLING_EXPENSES = [1612, 1613];

    /** "COMISSÃO / CTN": a compra dela sai do lucro do débito, mesmo sem estar na nota. */
    private const COMMISSION_EXPENSE = 239;

    private const BL_UNIT = 1;

    private const CONTAINER_UNIT = 2;

    /**
     * @param  bool  $regenerateExw  só a geração pelas despesas separa as despesas EXW do lucro e refaz `exw_expenses` e a observação;
     *                               incluir/remover item soma todos os itens no lucro e preserva o EXW digitado (como o legado)
     */
    public function __invoke(NoteType $type, int $noteId, bool $regenerateExw = false): void
    {
        $note = DB::table($type->table())->find($noteId);

        $items = DB::table("{$type->itemsTable()} as i")
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->leftJoin('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->where("i.{$type->itemColumn()}", $noteId)
            ->select('v.*', 'e.ref_unity as unity')
            ->get();

        $isExw = fn (object $v) => $regenerateExw && $type === NoteType::Debit && (int) $v->flag_exw === 1;
        $profit = $items->reject($isExw)->sum(fn (object $v) => ($v->selling_rate - $v->buying_rate) * $this->factor($v));
        $commission = $type === NoteType::Debit ? $this->commissionPurchase((int) $note->ref_embarque) : 0.0;
        $remainder = $profit - $commission;
        $hasHandling = $items->contains(fn (object $v) => in_array((int) $v->ref_expense, self::HANDLING_EXPENSES, true));

        $values = [
            'gross_profit' => $profit,
            'due_to_dmf' => $hasHandling ? $remainder : $remainder / 2,
            'due_to_dmf_agent' => $hasHandling ? 0 : $remainder / 2,
            'due_to_cnee_agent' => 0,
            'due_to_shipper_agent' => 0,
        ];

        if ($type === NoteType::Debit && $regenerateExw) {
            $exw = $items->filter($isExw);
            $values['exw_expenses'] = $exw->sum(fn (object $v) => $v->selling_rate * $this->factor($v));

            if ($exw->isNotEmpty()) {
                $values['obs'] = "EXW EXPENSES:\n\r".$exw->map(fn (object $v) => $this->exwLine($v))->implode("\n\r");
            }
        }

        DB::table($type->table())->where('id', $noteId)->update($values);
    }

    /** NoteItems::getFator (legado): BL = quantidade de BLs; container = quantidade de mercadorias (do equipamento, se houver); senão 1. */
    public function factor(object $expense): float
    {
        return match ((int) $expense->unity) {
            self::BL_UNIT => (float) DB::table('embarque')->where('embarque_id', $expense->ref_embarque)->value('qtd_bl'),
            self::CONTAINER_UNIT => (float) DB::table('mercadoria')
                ->where('embarque_id', $expense->ref_embarque)
                ->when($expense->ref_equipment, fn ($q, $equipment) => $q->where('tipo_container_id', $equipment))
                ->sum('quantidade'),
            default => 1.0,
        };
    }

    private function commissionPurchase(int $bookingId): float
    {
        $commission = DB::table('fin_expenses_values as v')
            ->leftJoin('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->where('v.ref_embarque', $bookingId)
            ->where('v.ref_expense', self::COMMISSION_EXPENSE)
            ->orderBy('v.id')
            ->select('v.*', 'e.ref_unity as unity')
            ->first();

        return $commission ? $commission->buying_rate * $this->factor($commission) : 0.0;
    }

    /** "Despesa/UN (equip) (BL) container: US$ 10,00 x 2,00 UN = US$ 20,00". */
    private function exwLine(object $v): string
    {
        $name = DB::table('fin_expenses as e')->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')->where('e.id', $v->ref_expense)->first(['e.name', 'u.acronym']);
        $equipment = $v->ref_equipment ? DB::table('tipo_container')->where('tipo_container_id', $v->ref_equipment)->value('nome') : null;
        $bl = $v->ref_bl ? DB::table('bl')->where('bl_id', $v->ref_bl)->value('bl') : null;
        $container = $v->ref_container ? DB::table('container')->where('container_id', $v->ref_container)->value('numero') : null;
        $currency = (string) DB::table('cmn_currencies')->where('id', $v->ref_currency_sr)->value('symbol');
        $factor = $this->factor($v);
        $money = fn (float $value) => $currency.' '.number_format($value, 2, ',', '.');

        return $name?->name.'/'.$name?->acronym
            .($equipment ? " ({$equipment})" : '')
            .($bl ? ' ('.+$bl.')' : '')
            .($container ? " {$container}" : '')
            .': '.$money($v->selling_rate).' x '.number_format($factor, 2, ',', '.').' '.$name?->acronym.' = '.$money($v->selling_rate * $factor);
    }
}
