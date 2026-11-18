<?php

namespace App\Actions\Booking;

use App\Enums\Notice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/verificaDespesas (legado): revisão diária das despesas de reserva lançadas ou alteradas hoje (fora as "all in"), um
 * e-mail para a importação e outro para a exportação. Lista as de venda (S/R) menor que a compra (B/R), comparadas em R$ pela
 * cotação de hoje da taxa padrão de cada moeda, e as de moedas diferentes em que falta cotação de hoje para alguma delas.
 *
 * Diferenças do legado: o e-mail só sai quando há algo a revisar (o legado mandava um e-mail vazio todo dia) e o texto da
 * exportação não repete o da importação (o legado não zerava a mensagem entre as duas operações).
 */
class ExpenseReviewNotice
{
    private const REAL = 1;

    private const LABELS = ['i' => 'IMPO', 'e' => 'EXPO'];

    private const BILLING = ['a' => 'PA', 'c' => 'CC', 'p' => 'PP', 's' => 'SEG', 'f' => 'FI'];

    /** @return int despesas listadas nos e-mails das duas operações */
    public function __invoke(): int
    {
        return $this->imports() + $this->exports();
    }

    public function imports(): int
    {
        return $this->review('i', Notice::ExpenseReviewImport);
    }

    public function exports(): int
    {
        return $this->review('e', Notice::ExpenseReviewExport);
    }

    private function review(string $operation, Notice $notice): int
    {
        if (! $notice->enabled()) {
            return 0;
        }

        $below = collect();
        $withoutRate = collect();

        foreach ($this->expenses($operation) as $expense) {
            $sameCurrency = $expense->ref_currency_br == $expense->ref_currency_sr;
            $purchase = $sameCurrency ? 1.0 : $this->factor($expense->ref_currency_br);
            $sale = $sameCurrency ? 1.0 : $this->factor($expense->ref_currency_sr);

            if ($purchase === null || $sale === null) {
                $withoutRate->push($expense);
            } elseif ($sale * $expense->selling_rate < $purchase * $expense->buying_rate) {
                $below->push($expense);
            }
        }

        if ($below->isEmpty() && $withoutRate->isEmpty()) {
            return 0;
        }

        $html = $this->section('Gentileza notar que as despesas abaixo possuem o S/R inferior ao B/R', $below)
            .$this->section('Gentileza notar que as despesas abaixo possuem moeda de compra e venda diferentes e não há cotação para uma das moedas.', $withoutRate);

        Mail::html($html, fn ($mail) => $mail
            ->from('financeiro@dmflog.com.br', 'DMF Logistics do Brasil')
            ->to($notice->recipients())
            ->subject('[DMF] Revisão de despesas - '.today()->format('d/m/Y').' - '.self::LABELS[$operation]));

        return $below->count() + $withoutRate->count();
    }

    /** @return Collection<int, object> */
    private function expenses(string $operation): Collection
    {
        $today = today()->toDateString();

        return DB::table('fin_expenses_values as v')
            ->join('embarque as e', 'e.embarque_id', '=', 'v.ref_embarque')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('fin_expenses as x', 'x.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'x.ref_unity')
            ->leftJoin('fin_expenses_values_due_to as d', 'd.id', '=', 'v.ref_due_to')
            ->leftJoin('cmn_currencies as cb', 'cb.id', '=', 'v.ref_currency_br')
            ->leftJoin('cmn_currencies as cs', 'cs.id', '=', 'v.ref_currency_sr')
            ->where(fn ($q) => $q->where('v.created_at', $today)->orWhere('v.updated_at', $today))
            ->where(fn ($q) => $q->where('v.all_in', '0')->orWhereNull('v.all_in'))
            ->where('e.impor_expor', $operation)
            ->orderBy('v.id')
            ->get(['v.ref_currency_br', 'v.ref_currency_sr', 'v.buying_rate', 'v.selling_rate', 'v.billing', 'e.booking', 'p.nome as client', 'p.cnpj',
                'x.name as expense', 'x.ref_unity', 'u.acronym as unity', 'd.name as due_to', 'cb.symbol as buying_symbol', 'cs.symbol as selling_symbol']);
    }

    /** Cotação de hoje da taxa padrão da moeda (1 para o real); null quando a moeda não tem taxa padrão ou cotação lançada hoje. */
    private function factor(mixed $currency): ?float
    {
        if ($currency == self::REAL) {
            return 1.0;
        }

        $rate = DB::table('fin_default_rates as fdr')
            ->join('fin_currencies_rates as f', 'f.id', '=', 'fdr.ref_currency_rate')
            ->where('f.ref_currency', $currency)
            ->orderBy('fdr.id')
            ->value('fdr.ref_currency_rate');

        $value = $rate ? DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $rate)->where('rate_date', today()->toDateString())->orderBy('id')->value('rate') : null;

        return $value === null ? null : (float) $value;
    }

    /** @param  Collection<int, object>  $expenses */
    private function section(string $intro, Collection $expenses): string
    {
        if ($expenses->isEmpty()) {
            return '';
        }

        return "<p>{$intro}</p><table><tr><th>Reserva</th><th>Cliente</th><th>Despesa</th><th>B/R</th><th>S/R</th><th>Billing</th><th>Due To</th></tr>"
            .$expenses->map(fn ($x) => '<tr><td>'.e($x->booking).'</td>'
                .'<td>'.e($x->client ? "{$x->client} ({$x->cnpj})" : '').'</td>'
                .'<td>'.e($x->expense.($x->ref_unity ? " / {$x->unity}" : '')).'</td>'
                .'<td>'.e("{$x->buying_symbol} ".number_format((float) $x->buying_rate, 2, ',', '.')).'</td>'
                .'<td>'.e("{$x->selling_symbol} ".number_format((float) $x->selling_rate, 2, ',', '.')).'</td>'
                .'<td>'.(self::BILLING[$x->billing] ?? '').'</td>'
                .'<td>'.e($x->due_to).'</td></tr>')->implode('')
            .'</table>';
    }
}
