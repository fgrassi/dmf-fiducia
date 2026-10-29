<?php

namespace App\Actions\PaymentOrders\Other;

use App\Actions\PaymentOrders\Generation\PaymentGenerationLock;
use App\Models\Embarque;
use App\Models\OrdemPagamento;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * executeGeraOrdemPagamento, geraOrdemPagamentoComSelecao e geraOrdemSelecionadas (legado, ordemPagamentoOutros): cria a
 * ordem de pagamento a despachante, cia aérea ou seguro com um item por despesa de terceiros da reserva. Os itens nascem
 * desligados (status nulo) e com taxa 0: o valor só sai ao recalcular com as taxas (X2), salvo desconto e valor mínimo.
 * Uma geração por vez (PaymentGenerationLock, o mesmo GET_LOCK do legado).
 *
 * Preserva o legado: a ordem fica mesmo com total 0; a reserva sai da fila (ctr_pgto_outros = 0) quando não sobra
 * despesa pendente, inclusive se a validação falhar sem pendências.
 * Diferenças: o legado gravava a reserva passo a passo sem transação, aqui cada reserva é uma transação; e uma reserva
 * de seguro/desembaraço/correio que também é exportação (ou importação aérea) é processada uma vez, não duas.
 */
class GenerateOtherPaymentOrders
{
    private const MODULE = 'ordemPagamentoOutros';

    private const NORMAL = 1;

    private const EXTRA = 2;

    /** ARBITRARY FEE: entra na ordem mesmo sem custo. */
    private const ALWAYS_EXPENSES = [33];

    public function __construct(
        private OtherPaymentOrderChecks $checks,
        private OtherOrderItemValue $value,
        private PaymentGenerationLock $lock,
    ) {}

    /**
     * Geração automática (botão "Gerar manualmente" da lista e cron): reservas com pagamento de outros pendente.
     *
     * @return int ordens criadas
     */
    public function all(): int
    {
        $created = 0;

        foreach ($this->pendingBookingIds() as $id) {
            $booking = Embarque::find($id);

            if ($booking) {
                $created += $this->forBooking($booking);
            }
        }

        return $created;
    }

    /** @return int ordens criadas */
    private function forBooking(Embarque $booking): int
    {
        return ($this->lock)(self::MODULE, fn () => DB::transaction(function () use ($booking) {
            $created = [];

            return $this->generate($booking, null, $created);
        }));
    }

    /**
     * geraOrdemSelecionadas (legado): gera a ordem só das despesas marcadas na aba da reserva. Tudo ou nada.
     *
     * @param  list<int>  $ids
     * @return array{success: bool, message: string, orders: list<int>, ignored: list<array{id: int, reason: string}>}
     *
     * @throws DomainException mensagem do legado
     */
    public function fromSelection(Embarque $booking, array $ids): array
    {
        if (! in_array($booking->impor_expor, ['i', 'e'], true)) {
            throw new DomainException('Reserva não encontrada ou sem modalidade de importação/exportação.');
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        return ($this->lock)(self::MODULE, fn () => DB::transaction(function () use ($booking, $ids) {
            $expenses = DB::table('fin_expenses_values')->where('ref_embarque', $booking->getKey())->whereIn('id', $ids)->get();

            if ($expenses->count() !== count($ids)) {
                throw new DomainException('Todas as despesas devem existir e pertencer à reserva informada.');
            }

            $pending = $this->checks->compatiblePendingIds($booking);
            $selected = [];
            $ignored = [];

            foreach ($expenses as $expense) {
                $reason = match (true) {
                    ! $this->checks->compatible($expense, $booking) => 'Due To, Billing ou valor de compra incompatível com este tipo de ordem.',
                    ! in_array((int) $expense->id, $pending, true) => 'Despesa já lançada em ordem de pagamento.',
                    ! $expense->ref_currency_br => throw new DomainException("Despesa {$expense->id} sem moeda de compra cadastrada."),
                    default => null,
                };

                $reason ? $ignored[] = ['id' => (int) $expense->id, 'reason' => $reason] : $selected[] = (int) $expense->id;
            }

            $orders = [];

            if ($selected !== []) {
                $this->generate($booking, $selected, $orders);
                $remaining = $this->checks->compatiblePendingIds($booking);

                foreach (array_intersect($selected, $remaining) as $id) {
                    $ignored[] = ['id' => $id, 'reason' => 'Não gerou item válido; revise os dados da despesa e da reserva.'];
                }

                $booking->forceFill(['ctr_pgto_outros' => $remaining === [] ? 0 : 1])->save();
            }

            return [
                'success' => $orders !== [],
                'message' => $orders !== [] ? count($orders).' ordem(ns) de pagamento gerada(s) com sucesso!' : 'Nenhuma ordem de pagamento gerada.',
                'orders' => $orders,
                'ignored' => $ignored,
            ];
        }));
    }

    /**
     * Reservas candidatas do modo automático (as seis consultas do executeGeraOrdemPagamento): importação marítima com
     * chegada confirmada, importação aérea, exportação de qualquer tipo, seguro, desembaraço e correio.
     *
     * @return Collection<int, int>
     */
    private function pendingBookingIds(): Collection
    {
        $base = fn () => DB::table('embarque as e')->where('e.ctr_pgto_outros', 1)->distinct();

        return collect()
            ->merge($base()->join('arrival_confirmation as a', 'a.ref_embarque', '=', 'e.embarque_id')->where('e.impor_expor', 'i')->where('e.tipo_embarque', 'm')->pluck('e.embarque_id'))
            ->merge($base()->where('e.impor_expor', 'i')->where('e.tipo_embarque', 'a')->pluck('e.embarque_id'))
            ->merge($base()->where('e.impor_expor', 'e')->pluck('e.embarque_id'))
            ->merge($base()->whereIn('e.tipo_embarque', ['s', 'd', 'r'])->pluck('e.embarque_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * geraOrdemPagamentoComSelecao: o núcleo, para uma reserva.
     *
     * @param  ?list<int>  $selectedIds  null = automático
     * @param  list<int>  $created  ids das ordens criadas
     * @return int ordens criadas
     *
     * @throws DomainException só com despesas selecionadas
     */
    private function generate(Embarque $booking, ?array $selectedIds, array &$created): int
    {
        $ids = $selectedIds ?? $this->checks->pendingIds($booking);

        if (! (($selectedIds !== null || $ids !== []) && $this->checks->valid($booking, $selectedIds))) {
            if ($ids === []) {
                $booking->forceFill(['ctr_pgto_outros' => 0])->save();
            }

            return 0;
        }

        $expenses = DB::table('fin_expenses_values as f')
            ->join('fin_expenses as fe', 'fe.id', '=', 'f.ref_expense')
            ->where('f.ref_embarque', $booking->getKey())
            ->whereNotIn('f.ref_due_to', [3, 10])
            ->whereIn('f.id', $ids)
            ->orderBy('fe.name')
            ->orderBy('f.id')
            ->get(['f.*']);

        if ($selectedIds !== null && $expenses->isEmpty()) {
            throw new DomainException('Nenhuma despesa selecionada possui cadastro válido para gerar a ordem.');
        }

        $order = OrdemPagamento::forceCreate([
            'ref_embarque' => $booking->getKey(),
            'created_at' => now()->toDateString(),
            'ref_type' => DB::table('ordem_pagamento')->where('ref_embarque', $booking->getKey())->exists() ? self::EXTRA : self::NORMAL,
            'status' => 0,
        ]);

        foreach ($expenses as $expense) {
            if ((float) $expense->buying_rate === 0.0 && ! in_array((int) $expense->ref_expense, self::ALWAYS_EXPENSES, true)) {
                continue;
            }

            $order->items()->forceCreate([
                'ref_expense' => $expense->id,
                'unitary_value' => $expense->buying_rate,
                'ref_currency' => $expense->ref_currency_br,
                'current_rate_value' => 0,
                'item_value' => $this->value->debit($expense, 0, $this->value->unitWithCommissions($booking, $expense), $booking),
                'indice' => $this->value->index($expense, $booking),
                'created_at' => now()->toDateString(),
            ]);
        }

        $order->forceFill(['total_value' => (float) $order->items()->sum('item_value')])->save();

        if ($selectedIds === null) {
            $booking->forceFill(['ctr_pgto_outros' => 0])->save();
        }

        $created[] = (int) $order->getKey();

        return 1;
    }
}
