<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * getDespesasIds, verificaDespesasOrfas, verificaDespesas e PaymentOrderSelection::isCompatible (legado, ordemPagamentoOutros):
 * o que a reserva precisa ter para gerar a ordem a despachante, cia aérea ou seguro. O legado avisava por e-mail, mas o envio
 * está comentado; no modo automático a reserva inválida só é pulada, no das despesas selecionadas a mensagem vira erro.
 *
 * Due To 1 (cliente), 3 (armador) e 10 (e-mail) nunca entram: o armador tem a própria ordem.
 */
class OtherPaymentOrderChecks
{
    private const EXCLUDED_DUE_TO = [1, 3, 10];

    /** Tipos de reserva em que a importação paga qualquer tipo de cobrança. */
    private const ALL_BILLING_TYPES = ['s', 'd', 'r'];

    /** Tipos em que não se exige BL, quantidade de BLs, equipamento nem billing. */
    private const UNCHECKED_TYPES = ['s', 'd', 'r', 'a'];

    private const UNIT_BL = 1;

    private const UNIT_CONTAINER = 2;

    /** getDespesasIds: despesas de terceiros com custo, ainda fora de qualquer ordem de Outros. @return list<int> */
    public function pendingIds(Embarque $booking): array
    {
        return DB::table('fin_expenses_values as f')
            ->where('f.ref_embarque', $booking->getKey())
            ->whereNotIn('f.ref_due_to', self::EXCLUDED_DUE_TO)
            ->where('f.buying_rate', '<>', 0)
            ->whereNotIn('f.id', DB::table('ordem_pagamento_item')->select('ref_expense'))
            ->when($this->billingRestricted($booking), fn ($q) => $q->whereIn('f.billing', ['c', 'a']))
            ->orderBy('f.id')
            ->pluck('f.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** isCompatible (módulo Outros): a despesa entra numa ordem de Outros desta reserva? */
    public function compatible(object $expense, Embarque $booking): bool
    {
        if ((float) $expense->buying_rate === 0.0 || ! $expense->ref_due_to || in_array((int) $expense->ref_due_to, self::EXCLUDED_DUE_TO, true)) {
            return false;
        }

        return ! $this->billingRestricted($booking) || in_array($expense->billing, ['a', 'c'], true);
    }

    /** PaymentOrderSelection::pendingIds. @return list<int> */
    public function compatiblePendingIds(Embarque $booking): array
    {
        return DB::table('fin_expenses_values')->whereIn('id', $this->pendingIds($booking))->orderBy('id')->get()
            ->filter(fn (object $expense) => $this->compatible($expense, $booking))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * verificaDespesas: a reserva tem o que a ordem exige (despesas, BLs, quantidade de BLs, equipamentos e tipo de billing).
     *
     * Preserva o legado: a verificação de equipamento é refeita a cada despesa de container com equipamento, então só a
     * última delas conta (um equipamento ausente numa despesa anterior passa se a última existir).
     *
     * @param  ?list<int>  $selectedIds
     *
     * @throws DomainException só com despesas selecionadas
     */
    public function valid(Embarque $booking, ?array $selectedIds): bool
    {
        $name = $booking->booking;
        $expenses = DB::table('fin_expenses_values as f')
            ->join('fin_expenses as fe', 'fe.id', '=', 'f.ref_expense')
            ->where('f.ref_embarque', $booking->getKey())
            ->whereNotIn('f.ref_due_to', [3, 10])
            ->when($selectedIds !== null, fn ($q) => $q->whereIn('f.id', $selectedIds))
            ->orderBy('f.id')
            ->get(['f.*', 'fe.ref_unity']);

        $problem = $expenses->isEmpty() ? "O embarque {$name} não possui despesas cadastradas" : $this->problem($booking, $expenses, $name);

        if ($problem !== null && $selectedIds !== null) {
            throw new DomainException($problem);
        }

        return $problem === null;
    }

    /** @param  Collection<int, \stdClass>  $expenses */
    private function problem(Embarque $booking, $expenses, string $name): ?string
    {
        if (in_array($booking->tipo_embarque, self::UNCHECKED_TYPES, true)) {
            return null;
        }

        $namedBls = DB::table('bl')->where('embarque_id', $booking->getKey())->whereNotNull('bl')->count();
        $equipment = DB::table('mercadoria')->where('embarque_id', $booking->getKey())->pluck('tipo_container_id');
        $noBl = $expenses->contains(fn ($e) => (int) $e->ref_unity === self::UNIT_BL) && (int) $booking->qtd_bl < 1;
        $equipmentMissing = false;

        foreach ($expenses->filter(fn ($e) => (int) $e->ref_unity === self::UNIT_CONTAINER && (int) $e->ref_equipment > 0) as $expense) {
            $equipmentMissing = ! $equipment->contains(fn ($id) => (int) $id === (int) $expense->ref_equipment);
        }

        return match (true) {
            $namedBls < 1 && $booking->tipo_embarque === 'm' => "O embarque {$name} não possui BLS cadastrados. Por favor revise esse processo.",
            $noBl => "O embarque {$name} não possui quantidade de BLS cadastrados e uma ou mais despesas sao do tipo BL. Por favor revise esse processo.",
            $equipmentMissing => "O embarque {$name} possui despesas com equipamentos não cadastrados. Por favor revise esse processo.",
            $expenses->contains(fn ($e) => $e->billing === null || $e->billing === '') => "O embarque {$name} possui despesas sem o tipo de Billing. Por favor revise esse processo.",
            default => null,
        };
    }

    /** Importação que não é seguro, desembaraço nem correio só paga despesas cobradas (c) ou no processo (a). */
    private function billingRestricted(Embarque $booking): bool
    {
        return $booking->impor_expor === 'i' && ! in_array($booking->tipo_embarque, self::ALL_BILLING_TYPES, true);
    }
}
