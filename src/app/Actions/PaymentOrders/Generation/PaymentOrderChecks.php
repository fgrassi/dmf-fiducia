<?php

namespace App\Actions\PaymentOrders\Generation;

use App\Models\Embarque;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * getDespesasIds, verificaDespesasOrfas, verificaDespesas, verificaCotacoes e PaymentOrderSelection::isCompatible (legado):
 * o que a reserva precisa ter para gerar a ordem de pagamento do armador. O legado avisava por e-mail, mas o envio está
 * comentado; no modo automático a reserva inválida só é pulada, no das despesas selecionadas a mensagem vira erro.
 */
class PaymentOrderChecks
{
    private const ARMADOR_DUE_TO = 3;

    private const MAIL_DUE_TO = 10;

    private const REAL = 1;

    private const OPERATIONS = ['i', 'e'];

    private const UNIT_BL = 1;

    private const UNIT_CONTAINER = 2;

    /** Incoterms em que a reserva paga tudo, qualquer que seja o tipo de cobrança. */
    private const ALL_BILLING_INCOTERMS = [1, 2];

    /** getDespesasIds: despesas do armador com custo, ainda fora de qualquer ordem. @return list<int> */
    public function pendingIds(Embarque $booking): array
    {
        return DB::table('fin_expenses_values as f')
            ->where('f.ref_embarque', $booking->getKey())
            ->where('f.ref_due_to', self::ARMADOR_DUE_TO)
            ->where('f.buying_rate', '<>', 0)
            ->whereNotIn('f.id', DB::table('fin_payment_order_items')->select('ref_expense'))
            ->orderBy('f.id')
            ->pluck('f.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** verificaDespesasOrfas (modo automático): há despesa pendente do tipo de cobrança que a ordem cobre? */
    public function hasOrphans(Embarque $booking): bool
    {
        $query = DB::table('fin_expenses_values as f')
            ->where('f.ref_embarque', $booking->getKey())
            ->where('f.ref_due_to', self::ARMADOR_DUE_TO)
            ->where('f.buying_rate', '<>', 0)
            ->whereNotIn('f.id', DB::table('fin_payment_order_items')->select('ref_expense'));

        if ($booking->tipo_embarque !== 's') {
            if ($booking->impor_expor === 'i') {
                $query->where('f.billing', 'c');
            } elseif ($booking->impor_expor === 'e' && ! in_array((int) $booking->incoterm_id, self::ALL_BILLING_INCOTERMS, true)) {
                $query->whereIn('f.billing', ['p', 'a']);
            }
        }

        return $query->exists();
    }

    /**
     * Despesas da ordem: do armador, de acordo com o tipo de cobrança da operação, em ordem de nome.
     *
     * @param  list<int>  $ids
     * @return Collection<int, \stdClass>
     */
    public function expenses(Embarque $booking, array $ids, bool $nonZeroOnly = false)
    {
        $query = DB::table('fin_expenses_values as f')
            ->join('fin_expenses as fe', 'fe.id', '=', 'f.ref_expense')
            ->where('f.ref_embarque', $booking->getKey())
            ->whereIn('f.id', $ids)
            ->where('f.ref_due_to', self::ARMADOR_DUE_TO)
            ->when($nonZeroOnly, fn (Builder $q) => $q->where('f.buying_rate', '<>', 0)->whereNotNull('f.buying_rate'))
            ->orderBy('fe.name')
            ->orderBy('f.id');

        if ($booking->impor_expor === 'i') {
            $query->whereIn('f.billing', ['a', 'c']);
        } elseif (! in_array((int) $booking->incoterm_id, self::ALL_BILLING_INCOTERMS, true)) {
            $query->whereIn('f.billing', ['p', 'a']);
        }

        return $query->get(['f.*']);
    }

    /** isCompatible (ordemPagamento): a despesa entra numa ordem do armador desta reserva? */
    public function compatible(object $expense, Embarque $booking): bool
    {
        if ((float) $expense->buying_rate === 0.0 || (int) $expense->ref_due_to !== self::ARMADOR_DUE_TO) {
            return false;
        }

        return $booking->impor_expor === 'i'
            ? in_array($expense->billing, ['a', 'c'], true)
            : in_array((int) $booking->incoterm_id, self::ALL_BILLING_INCOTERMS, true) || in_array($expense->billing, ['p', 'a'], true);
    }

    /** PaymentOrderSelection::pendingIds. @return list<int> */
    public function compatiblePendingIds(Embarque $booking): array
    {
        $pending = $this->pendingIds($booking);

        return DB::table('fin_expenses_values')->whereIn('id', $pending)->orderBy('id')->get()
            ->filter(fn (object $expense) => $this->compatible($expense, $booking))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * verificaDespesas: a reserva tem o que a ordem exige (despesas, BLs, quantidade de BLs, equipamentos e tipo de cobrança).
     *
     * @param  ?list<int>  $selectedIds
     *
     * @throws DomainException só com despesas selecionadas
     */
    public function valid(Embarque $booking, ?array $selectedIds): bool
    {
        $expenses = DB::table('fin_expenses_values as f')
            ->join('fin_expenses as fe', 'fe.id', '=', 'f.ref_expense')
            ->where('f.ref_embarque', $booking->getKey())
            ->where('f.ref_due_to', '<>', self::MAIL_DUE_TO)
            ->where('f.buying_rate', '<>', 0)
            ->when($selectedIds !== null, fn (Builder $q) => $q->whereIn('f.id', $selectedIds))
            ->get(['f.*', 'fe.ref_unity']);

        if ($expenses->isEmpty()) {
            return false;
        }

        $name = $booking->booking;
        $hasBls = DB::table('bl')->where('embarque_id', $booking->getKey())->whereNotNull('bl')->exists();
        $equipment = DB::table('mercadoria')->where('embarque_id', $booking->getKey())->pluck('tipo_container_id')->map(fn ($id) => (int) $id);

        $problem = match (true) {
            ! $hasBls && $booking->tipo_embarque === 'm' => "O embarque {$name} não possui BLS cadastrados. Por favor revise esse processo.",
            $expenses->contains(fn ($e) => (int) $e->ref_unity === self::UNIT_BL) && (int) $booking->qtd_bl < 1 => "O embarque {$name} não possui quantidade de BLS cadastrados e uma ou mais despesas sao do tipo BL. Por favor revise esse processo.",
            $expenses->contains(fn ($e) => (int) $e->ref_unity === self::UNIT_CONTAINER && (int) $e->ref_equipment > 0 && ! $equipment->contains((int) $e->ref_equipment)) => "O embarque {$name} possui despesas com equipamentos não cadastrados. Por favor revise esse processo.",
            $expenses->contains(fn ($e) => blank($e->billing)) => "O embarque {$name} possui despesas sem o tipo de Billing. Por favor revise esse processo.",
            default => null,
        };

        if ($problem === null) {
            return true;
        }

        if ($selectedIds !== null) {
            throw new DomainException($problem);
        }

        return false;
    }

    /** verificaCotacoes (sem ordem): toda moeda estrangeira da reserva tem cotação de hoje do armador para pagamento? @param  ?list<int>  $selectedIds */
    public function quotesUpToDate(Embarque $booking, ?array $selectedIds): bool
    {
        if (! in_array($booking->impor_expor, self::OPERATIONS, true)) {
            return true;
        }

        foreach ($this->currencies($booking, $selectedIds) as $currency) {
            $exists = DB::table('fin_currencies_rates_armador_payment_values as v')
                ->join('fin_currencies_rates_armador_payment as p', 'p.id', '=', 'v.ref_currency_rate_armador')
                ->where('v.rate_date', now()->toDateString())
                ->where('p.ref_armador', $booking->paymentCarrierId())
                ->where('p.ref_currency', $currency)
                ->where('p.type', $booking->impor_expor)
                ->exists();

            if (! $exists) {
                return false;
            }
        }

        return true;
    }

    /** getBookingCurrencies (sem ordem). @param  ?list<int>  $selectedIds @return list<int> */
    private function currencies(Embarque $booking, ?array $selectedIds): array
    {
        $query = DB::table('fin_expenses_values as q')->where('q.ref_embarque', $booking->getKey())->where('q.ref_currency_br', '<>', self::REAL);

        if ($selectedIds !== null) {
            $query->whereIn('q.id', $selectedIds);
        } else {
            $query->where('q.buying_rate', '<>', 0)->where('q.ref_due_to', self::ARMADOR_DUE_TO);

            if ($booking->impor_expor === 'i') {
                $query->where('q.billing', 'c');
            } elseif ($booking->impor_expor === 'e' && ! in_array((int) $booking->incoterm_id, self::ALL_BILLING_INCOTERMS, true)) {
                $query->whereIn('q.billing', ['p', 'a']);
            }
        }

        return $query->distinct()->pluck('q.ref_currency_br')->map(fn ($id) => (int) $id)->all();
    }
}
