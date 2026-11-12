<?php

namespace App\Actions\Booking;

use App\Actions\VariedExpenses\BookingExpenseLines;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Embarque::getTotalizadorExpenses (legado), "Resumo de Custos e Receitas": receita − despesa por Due To e moeda,
 * em R$ pela última taxa da moeda. Despesa sem Due To fica de fora; moeda de compra ≠ venda divide a despesa em dois baldes.
 *
 * @phpstan-type Quote array{name: string, date: ?string, value: float}
 * @phpstan-type Row array{to: string, currency: string, cost: float, sale: float, balance: float, quote: ?Quote, balance_brl: ?float}
 */
class ExpenseSummary
{
    private const UNIT_CONTAINER = 2;

    private const UNIT_WEIGHT_MEASURE = 3;

    public function __construct(private BookingExpenseLines $expenseLines) {}

    /**
     * @param  object  $booking  Embarque ou linha de `embarque`
     * @return array{rows: list<Row>, total: float, complete: bool}
     */
    public function __invoke(object $booking): array
    {
        $bls = DB::table('bl')->where('embarque_id', $booking->embarque_id)->orderBy('bl_id')->get();
        $buckets = [];

        $expenses = DB::table('fin_expenses_values as v')
            ->leftJoin('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->join('fin_expenses_values_due_to as d', 'd.id', '=', 'v.ref_due_to')
            ->leftJoin('cmn_currencies as cb', 'cb.id', '=', 'v.ref_currency_br')
            ->leftJoin('cmn_currencies as cs', 'cs.id', '=', 'v.ref_currency_sr')
            ->where('v.ref_embarque', $booking->embarque_id)
            ->orderBy('v.id')
            ->get(['v.*', 'e.id as expense_id', 'e.ref_unity', 'd.name as due_to_name', 'cb.symbol as br_symbol', 'cs.symbol as sr_symbol']);

        foreach ($expenses as $expense) {
            $factor = $this->totalFactor($expense, $booking, $bls);
            $cost = (float) $expense->buying_rate * $factor;
            $revenue = (float) $expense->selling_rate * $factor;
            $cost = $expense->br_min > 0 && $cost < $expense->br_min ? (float) $expense->br_min : $cost;
            $revenue = $expense->sr_min > 0 && $revenue < $expense->sr_min ? (float) $expense->sr_min : $revenue;
            $buySymbol = $expense->br_symbol;
            $saleSymbol = filled($expense->sr_symbol) ? $expense->sr_symbol : $buySymbol;

            if ($buySymbol === $saleSymbol) {
                $this->addBucket($buckets, $expense->due_to_name, $buySymbol, $expense->ref_currency_br, $cost, $revenue);
            } else {
                if ($cost != 0) {
                    $this->addBucket($buckets, $expense->due_to_name, $buySymbol, $expense->ref_currency_br, $cost, 0);
                }

                if ($revenue != 0) {
                    $this->addBucket($buckets, $expense->due_to_name, $saleSymbol, $expense->ref_currency_sr, 0, $revenue);
                }
            }
        }

        $total = 0.0;
        $complete = true;
        $quotes = [];
        $rows = [];

        foreach ($buckets as $bucket) {
            $quote = $quotes[$bucket['currency_id']] ??= $this->latestRate($bucket['currency_id'], $bucket['currency']);
            $balance = $bucket['sale'] - $bucket['cost'];
            $inReais = $quote ? $balance * $quote['value'] : null;

            $quote ? $total += $inReais : $complete = false;

            $rows[] = [
                'to' => $bucket['to'], 'currency' => $bucket['currency'], 'cost' => $bucket['cost'], 'sale' => $bucket['sale'],
                'balance' => $balance, 'quote' => $quote, 'balance_brl' => $inReais,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'complete' => $complete];
    }

    /** @param  array<string, array<string, mixed>>  $buckets */
    private function addBucket(array &$buckets, string $to, ?string $symbol, mixed $currencyId, float $cost, float $sale): void
    {
        $symbol = filled($symbol) ? $symbol : '-';
        $bucket = &$buckets["{$to}|{$symbol}"];
        $bucket ??= ['to' => $to, 'currency' => $symbol, 'currency_id' => $currencyId, 'cost' => 0.0, 'sale' => 0.0];
        $bucket['cost'] += $cost;
        $bucket['sale'] += $sale;
    }

    /**
     * Embarque::getUltimaTaxaConversaoReal (legado): real = 1; dólar = última Taxa Diária; demais = última taxa da taxa padrão.
     *
     * @return ?Quote
     */
    private function latestRate(mixed $currencyId, string $symbol): ?array
    {
        if ($symbol === 'R$') {
            return ['name' => 'Real', 'date' => null, 'value' => 1.0];
        }

        if (! $currencyId) {
            return null;
        }

        $query = DB::table('fin_currencies_rates_values as v')->join('fin_currencies_rates as r', 'r.id', '=', 'v.ref_currency_rate')
            ->where('r.ref_currency', $currencyId)->where('v.rate', '>', 0)->orderByDesc('v.rate_date')->orderByDesc('v.id');

        $symbol === 'US$'
            ? $query->where('r.name', 'like', 'Taxa Diária%')
            : $query->whereIn('r.id', DB::table('fin_default_rates')->select('ref_currency_rate'));

        $row = $query->first(['v.rate', 'v.rate_date', 'r.name']);

        return $row ? ['name' => $row->name, 'date' => $row->rate_date, 'value' => (float) $row->rate] : null;
    }

    /**
     * Embarque::getFatorTotalDespesa (legado): soma dos fatores da despesa nos BLs da reserva (ou, sem BL, nas mercadorias).
     *
     * @param  Collection<int, stdClass>  $bls
     */
    private function totalFactor(stdClass $expense, object $booking, Collection $bls): float
    {
        if (! $expense->expense_id) {
            return 1.0;
        }

        if ($bls->isNotEmpty()) {
            $factor = $bls
                ->filter(fn (stdClass $bl) => ! $expense->ref_bl || (int) $expense->ref_bl === (int) $bl->bl_id)
                ->filter(fn (stdClass $bl) => ! $expense->ref_container || (int) DB::table('container')->where('container_id', $expense->ref_container)->value('bl_id') === (int) $bl->bl_id)
                ->sum(fn (stdClass $bl) => $this->expenseLines->factor($expense, $bl));
        } elseif ((int) $expense->ref_unity === self::UNIT_CONTAINER) {
            $factor = (float) DB::table('mercadoria')->where('embarque_id', $booking->embarque_id)
                ->when($expense->ref_equipment, fn ($query, $type) => $query->where('tipo_container_id', $type))->sum('quantidade');
        } elseif ((int) $expense->ref_unity === self::UNIT_WEIGHT_MEASURE) {
            $weight = (float) $booking->peso_cotacao;
            $volume = (float) $booking->volume_cotacao;
            $factor = $booking->impor_expor === 'i'
                ? (($weight || $volume) ? max($booking->tipo_embarque === 'm' ? $volume : $volume / 0.006, $weight) : 0)
                : max($volume / 0.006, $weight);
        } else {
            $factor = 1.0;
        }

        return $factor > 0 ? (float) $factor : 1.0;
    }
}
