<?php

namespace App\Actions\Booking\Confirmation;

use App\Models\Embarque;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Embarque::verificaCustos e validaDespesas (legado): compara a compra (B/R) das despesas da reserva com a matriz do armador no
 * porto (`fin_expenses_matrix`, vigente na data, mesma despesa, moeda, cobrança e equipamento). Matriz com valor maior que a
 * compra é divergência. Na saída de exportação a divergência bloqueia a confirmação; na chegada de importação só avisa o financeiro.
 */
class ExpenseMatrixCheck
{
    /** Contêineres (tipo 32 a 35) que dispensam a conferência da saída. */
    private const EXEMPT_CONTAINERS = [32, 33, 34, 35];

    /**
     * verificaCustos: só marítimo, na data efetiva da saída.
     *
     * @return array{ok: bool, message: string, expenses: Collection<int, object>}
     */
    public function forDeparture(Embarque $booking, string $date): array
    {
        $none = collect();

        if ($booking->tipo_embarque !== 'm') {
            return ['ok' => true, 'message' => '', 'expenses' => $none];
        }

        if (! $booking->armador_id || ! $booking->porto_origem_id) {
            return ['ok' => false, 'message' => 'Não há armador ou porto de origem cadastrado para esta reserva.', 'expenses' => $none];
        }

        $carrierPort = $this->carrierPort($booking->armador_id, $booking->porto_origem_id);

        if (! $carrierPort) {
            $carrier = DB::table('armador')->where('armador_id', $booking->armador_id)->value('nome');
            $port = DB::table('porto')->where('porto_id', $booking->porto_origem_id)->value('nome');

            return ['ok' => false, 'message' => "Não há Matriz de Despesas para o Armador {$carrier} na origem {$port}", 'expenses' => $none];
        }

        if (DB::table('mercadoria')->where('embarque_id', $booking->getKey())->whereIn('tipo_container_id', self::EXEMPT_CONTAINERS)->exists()) {
            return ['ok' => true, 'message' => '', 'expenses' => $none];
        }

        $expenses = $this->divergences($booking, $carrierPort, $date, 'e');

        return $expenses->isEmpty()
            ? ['ok' => true, 'message' => '', 'expenses' => $none]
            : ['ok' => false, 'message' => 'Não foi possível fazer a confirmação de embarque, pois os valores de compra estão diferentes da Matriz do Armador. Favor revisar as despesas abaixo:', 'expenses' => $expenses];
    }

    /** validaDespesas: importação com despesas revisadas, na data efetiva da chegada. */
    public function hasArrivalDivergence(Embarque $booking, string $date): bool
    {
        if ($booking->impor_expor !== 'i' || $booking->revisao_despesas !== 's') {
            return false;
        }

        $carrierPort = $this->carrierPort($booking->armador_id, $booking->porto_destino_id);

        return $carrierPort !== null && $this->divergences($booking, $carrierPort, $date, 'i')->isNotEmpty();
    }

    private function carrierPort(?int $carrierId, ?int $portId): ?int
    {
        return DB::table('cmn_armador_porto')->where('ref_armador', $carrierId)->where('ref_porto', $portId)->value('id');
    }

    /**
     * Linhas da matriz com valor maior que a compra de alguma despesa da reserva. Despesa sem compra (nula) fica de fora; a de compra
     * zero entra, como no legado (o Doctrine devolve "0.00", que é verdadeiro). Diferença do legado: na chegada ele dava erro de SQL
     * com uma despesa de compra nula, depois de gravar a chegada, e o aviso nunca saía.
     *
     * @return Collection<int, object>
     */
    private function divergences(Embarque $booking, int $carrierPort, string $date, string $type): Collection
    {
        $found = collect();

        foreach (DB::table('fin_expenses_values')->where('ref_embarque', $booking->getKey())->whereNotNull('buying_rate')->get() as $expense) {
            $found = $found->concat(
                DB::table('fin_expenses_matrix as m')
                    ->where('m.ref_armador_porto', $carrierPort)
                    ->where('m.due_from', '<=', $date)
                    ->where(fn ($q) => $q->where('m.due_to', '>', $date)->orWhereNull('m.due_to'))
                    ->where('m.ref_expense', $expense->ref_expense)
                    ->where('m.ref_currency', $expense->ref_currency_br)
                    ->where('m.expense_value', '>', $expense->buying_rate)
                    ->where('m.billing', $expense->billing)
                    ->where('m.type', $type)
                    ->when($expense->ref_equipment, fn ($q, $equipment) => $q->where('m.ref_equipamento', $equipment), fn ($q) => $q->whereNull('m.ref_equipamento'))
                    ->get(['m.id', 'm.ref_expense', 'm.expense_value', 'm.ref_currency', 'm.billing']),
            );
        }

        return $found;
    }
}
