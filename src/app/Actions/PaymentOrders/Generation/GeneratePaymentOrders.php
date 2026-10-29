<?php

namespace App\Actions\PaymentOrders\Generation;

use App\Actions\PaymentOrders\OrderItemValue;
use App\Models\Embarque;
use App\Models\FinPaymentOrder;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * geraOrdemPagamento, ordemManual e geraOrdemSelecionadas (legado, ordemPagamento): cria as ordens de pagamento ao armador,
 * uma por MBL da reserva, com um item por despesa do armador (Due To 3) e a taxa de hoje, e dá a reserva por paga
 * (ctr_pgto = 0). Ordem sem valor é apagada. Uma geração por vez (PaymentGenerationLock).
 *
 * Preserva o legado: no modo automático o item recebe como BL o último BL da reserva (a variável do laço vaza no PHP) e as
 * despesas de reservas consolidadas do mesmo MBL entram na mesma ordem.
 * Diferença: o legado, ao falhar numa reserva do lote (cotação do dia que não existe para uma despesa de cobrança PA, por
 * exemplo), derrubava o cron inteiro; aqui a reserva é desfeita e as outras seguem.
 */
class GeneratePaymentOrders
{
    private const MODULE = 'ordemPagamento';

    private const NORMAL = 1;

    private const EXTRA = 2;

    public function __construct(
        private PaymentOrderChecks $checks,
        private OrderItemValue $value,
        private PaymentGenerationLock $lock,
    ) {}

    /**
     * Geração automática do módulo inteiro (botão "Gerar manualmente" da lista e cron): reservas com pagamento pendente,
     * importação com chegada confirmada, exportação com saída confirmada, consolidadas ou não.
     *
     * @return int ordens criadas
     */
    public function all(): int
    {
        $created = 0;

        foreach ($this->pendingBookingIds() as $id) {
            $booking = Embarque::find($id);

            if (! $booking) {
                continue;
            }

            try {
                $created += $this->forBooking($booking);
            } catch (DomainException $e) {
                if ($e->getMessage() === PaymentGenerationLock::BUSY) {
                    throw $e;
                }

                report($e);
            }
        }

        return $created;
    }

    /** ordemManual (legado): gera as ordens de uma reserva escolhida. @return int ordens criadas */
    public function forBooking(Embarque $booking): int
    {
        return ($this->lock)(self::MODULE, fn () => DB::transaction(function () use ($booking) {
            $created = [];

            return $this->generate($booking, null, $created);
        }));
    }

    /**
     * geraOrdemSelecionadas (legado): gera as ordens só das despesas marcadas na aba da reserva. Tudo ou nada.
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

                $booking->forceFill(['ctr_pgto' => $remaining === [] ? 0 : 1])->save();
            }

            return [
                'success' => $orders !== [],
                'message' => $orders !== [] ? count($orders).' ordem(ns) de pagamento gerada(s) com sucesso!' : 'Nenhuma ordem de pagamento gerada.',
                'orders' => $orders,
                'ignored' => $ignored,
            ];
        }));
    }

    /** Reservas candidatas do modo automático (as três consultas do executeGeraOrdemPagamento). @return Collection<int, int> */
    private function pendingBookingIds(): Collection
    {
        $base = fn () => DB::table('embarque as e')->where('e.ctr_pgto', 1)->whereNotNull('e.master_bl')->distinct();

        return collect()
            ->merge($base()->join('arrival_confirmation as a', 'a.ref_embarque', '=', 'e.embarque_id')->where('e.impor_expor', 'i')->where('e.consolidado', 'n')->pluck('e.embarque_id'))
            ->merge($base()->join('departure_confirmation as d', 'd.ref_embarque', '=', 'e.embarque_id')->where('e.impor_expor', 'e')->where('e.consolidado', 'n')->pluck('e.embarque_id'))
            ->merge($base()->join('departure_confirmation as d', 'd.ref_embarque', '=', 'e.embarque_id')->where('e.impor_expor', 'e')->where('e.consolidado', 's')->pluck('e.embarque_id'))
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

        if (($selectedIds === null && ! $this->checks->hasOrphans($booking)) || ! $this->checks->valid($booking, $selectedIds)) {
            return 0;
        }

        $bls = DB::table('bl')->where('embarque_id', $booking->getKey())->orderBy('bl_id')->get();
        $mbls = $bls->map(fn (stdClass $bl) => trim((string) $bl->mbl_number))
            ->filter(fn (string $mbl) => $selectedIds === null || $mbl !== '')->unique()->values();

        if ($selectedIds !== null) {
            match (true) {
                $mbls->isEmpty() => throw new DomainException('Cadastre um BL com MBL para gerar a ordem de pagamento.'),
                ! $booking->paymentCarrierId() => throw new DomainException('Cadastre o armador para pagamento da reserva.'),
                ! $this->checks->quotesUpToDate($booking, $selectedIds) => throw new DomainException('Há moedas sem cotação de pagamento atualizada para o armador.'),
                default => null,
            };
        }

        $count = 0;

        foreach ($mbls as $mbl) {
            $others = $selectedIds === null && $mbl !== '' ? $this->consolidatedBookings($booking, $mbl) : collect();
            $order = $this->createOrder($booking, $mbl);

            if ($selectedIds !== null || $this->checks->quotesUpToDate($booking, null)) {
                $this->addItems($order, $booking, $this->checks->expenses($booking, $ids), $selectedIds, $bls, $mbl);

                foreach ($others as $other) {
                    $otherIds = $this->checks->pendingIds($other);

                    if ($otherIds !== []) {
                        $this->addItems($order, $booking, $this->checks->expenses($other, $otherIds, nonZeroOnly: true), null, $bls, $mbl, extra: true);
                    }
                }
            }

            $total = (float) $order->items()->sum('item_value');

            if ($total <= 0) {
                $order->delete();

                continue;
            }

            $order->forceFill(['total_value' => $total])->save();

            if ($selectedIds === null) {
                $booking->forceFill(['ctr_pgto' => 0])->save();
            }

            $others->each(fn (Embarque $other) => $other->forceFill(['ctr_pgto' => 0])->save());
            $created[] = (int) $order->getKey();
            $count++;
        }

        return $count;
    }

    private function createOrder(Embarque $booking, string $mbl): FinPaymentOrder
    {
        $carrier = $booking->paymentCarrierId();
        $banks = $carrier ? DB::table('fin_armador_banks')->where('ref_armador', $carrier)->whereNull('deleted_at')->pluck('id') : collect();

        return FinPaymentOrder::forceCreate([
            'ref_embarque' => $booking->getKey(),
            'created_at' => now()->toDateString(),
            'ref_type' => DB::table('fin_payment_orders')->where('ref_embarque', $booking->getKey())->exists() ? self::EXTRA : self::NORMAL,
            'ref_armador' => $carrier,
            'status' => 0,
            'mbl' => $mbl,
            'fin_armador_bank_id' => $banks->count() === 1 ? $banks->first() : null,
        ]);
    }

    /**
     * Itens da ordem: valor unitário = custo, moeda do custo, taxa de hoje (ou negociada) e valor em reais.
     *
     * @param  Collection<int, stdClass>  $expenses
     * @param  Collection<int, stdClass>  $bls  BLs da reserva
     * @param  ?list<int>  $selectedIds
     */
    private function addItems(FinPaymentOrder $order, Embarque $booking, Collection $expenses, ?array $selectedIds, Collection $bls, string $mbl, bool $extra = false): void
    {
        $carrier = $booking->paymentCarrierId();

        foreach ($expenses as $expense) {
            if ($selectedIds !== null && $expense->ref_bl && trim((string) $bls->firstWhere('bl_id', $expense->ref_bl)?->mbl_number) !== $mbl) {
                continue;
            }

            if ((float) $expense->buying_rate === 0.0) {
                continue;
            }

            $rate = $this->value->quote((int) $expense->ref_currency_br, $carrier, $booking->impor_expor, $order->getKey(), (int) $expense->id);
            $value = $this->value->debit($expense, $rate, (float) $expense->buying_rate, $mbl);

            if ($selectedIds !== null && $value === 0.0) {
                continue;
            }

            $order->items()->forceCreate([
                'ref_payment_order' => $order->getKey(),
                'ref_expense' => $expense->id,
                'unitary_value' => $expense->buying_rate,
                'ref_currency' => $expense->ref_currency_br,
                'current_rate_value' => $rate,
                'item_value' => $value,
                'created_at' => now()->toDateString(),
                'ref_bl' => $this->itemBl($expense, $bls, $selectedIds, $mbl),
            ]);
        }
    }

    /** BL do item: no automático, o último BL da reserva (como o legado); nas selecionadas, o primeiro do MBL compatível. */
    private function itemBl(stdClass $expense, Collection $bls, ?array $selectedIds, string $mbl): ?int
    {
        if ($selectedIds === null) {
            return (int) $bls->last()?->bl_id;
        }

        $bl = $bls->first(fn (stdClass $bl) => trim((string) $bl->mbl_number) === $mbl && (! $expense->ref_bl || (int) $expense->ref_bl === (int) $bl->bl_id));

        return $bl ? (int) $bl->bl_id : null;
    }

    /** Outras reservas consolidadas com BL do mesmo MBL, que pagam junto. @return Collection<int, Embarque> */
    private function consolidatedBookings(Embarque $booking, string $mbl): Collection
    {
        return Embarque::query()
            ->where('consolidado', 's')
            ->where('embarque_id', '<>', $booking->getKey())
            ->whereIn('embarque_id', DB::table('bl')->select('embarque_id')->where('mbl_number', $mbl))
            ->get();
    }
}
