<?php

namespace App\Actions\Financeiro;

use App\Enums\Notice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/provisionamento (legado): dois e-mails à Marcia e ao TI com a previsão de receita e despesa por armador, cliente e reserva
 * da semana (domingo a sábado) daqui a 2 semanas: exportações pela saída prevista (ETD) e importações pela chegada prevista (ETA),
 * reservas marítimas não canceladas.
 *
 * A previsão (Embarque::getPrevisaoProvisionamento) soma as despesas "Due To" 3 da reserva que não são do faturamento da operação
 * ('c' na exportação, 'p' na importação), no valor de compra e de venda vezes o número de BLs (unidade BL) ou de containers, com a
 * conversão em R$ pela cotação 3 (US$) ou 6 (€) lançada desde o domingo da semana passada. O vencimento é o do prazo do boleto do
 * cliente, ou a saída prevista quando ele não tem boleto (também nas importações, como no legado).
 *
 * Diferenças do legado: sem cotação lançada na janela vale a última cotação existente (o legado quebrava a rotina inteira) e moeda
 * sem cotação (nem R$, US$ ou €) converte para zero (o legado reaproveitava a taxa da despesa anterior).
 */
class ProvisioningNotice
{
    private const DOLLAR_RATE = 3;

    private const EURO_RATE = 6;

    private const DUE_TO = 3;

    /** @return int reservas listadas nos dois e-mails */
    public function __invoke(): int
    {
        if (! Notice::Provisioning->enabled()) {
            return 0;
        }

        $week = (int) today()->format('W');
        $year = today()->year;
        $from = today()->setISODate($year, $week + 2, 0)->toDateString();
        $to = today()->setISODate($year, $week + 2, 6)->toDateString();
        $rates = $this->rates(today()->setISODate($year, $week - 1, 0)->toDateString());

        $listed = 0;

        foreach ([
            'e' => ['EXPORTAÇÕES', 'ETD', 'saida_prevista'],
            'i' => ['IMPORTAÇÕES', 'ETA', 'chegada_prevista'],
        ] as $operation => [$title, $dateLabel, $dateColumn]) {
            [$html, $count] = $this->report($operation, $dateLabel, $dateColumn, $from, $to, $rates);

            Mail::html($html, fn ($mail) => $mail->to(Notice::Provisioning->recipients())->subject("Provisionamento {$title} - Semana {$week} de {$year}"));

            $listed += $count;
        }

        return $listed;
    }

    /** @return array<string, float> cotação em R$ por símbolo da moeda */
    private function rates(string $since): array
    {
        $rate = fn (int $id) => (float) (DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $id)->where('created_at', '>', $since)->orderByDesc('rate_date')->value('rate')
            ?? DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $id)->orderByDesc('rate_date')->value('rate'));

        return ['R$' => 1.0, 'US$' => $rate(self::DOLLAR_RATE), '€' => $rate(self::EURO_RATE)];
    }

    /**
     * @param  array<string, float>  $rates
     * @return array{0: string, 1: int} HTML e número de reservas listadas
     */
    private function report(string $operation, string $dateLabel, string $dateColumn, string $from, string $to, array $rates): array
    {
        $bookings = DB::table('embarque as e')
            ->join('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('armador as a', 'a.armador_id', '=', 'e.armador_id')
            ->whereBetween("e.{$dateColumn}", [$from, $to])
            ->where('e.finalizado', '<>', 'c')
            ->where('e.impor_expor', $operation)
            ->where('e.tipo_embarque', 'm')
            ->orderBy('e.embarque_id')
            ->get(['e.embarque_id', 'e.booking', 'e.cliente_id', 'e.saida_prevista', 'e.chegada_prevista', "e.{$dateColumn} as date", 'p.nome as cliente', 'a.nome as armador']);

        $forecasts = $this->forecasts($bookings->pluck('embarque_id')->all(), $operation, $rates);
        $currencies = $forecasts->flatMap(fn ($f) => array_keys($f))->unique()->values()->all();

        $listed = $bookings->filter(fn ($b) => ($forecasts[$b->embarque_id] ?? []) !== [])->values();
        $boletos = DB::table('fin_clients_boleto')->whereIn('ref_client', $listed->pluck('cliente_id')->all())->orderBy('id')->get()->unique('ref_client')->keyBy('ref_client');

        $html = '<h1>Provisionamento</h1><h2>Semana: '.date('d/m/Y', strtotime($from)).' a '.date('d/m/Y', strtotime($to)).'</h2><table border=1>';

        // armador > cliente > reservas, na ordem em que aparecem
        foreach ($listed->groupBy(fn ($b) => (string) $b->armador) as $carrier => $carrierBookings) {
            $html .= "<tr><th colspan='".((count($currencies) - 1) * 4 + 6)."'>Armador: ".e($carrier).'</th></tr>'
                .'<tr><th></th><th></th><th></th><th></th>'
                .collect($currencies)->map(fn ($c) => $c === 'R$' ? "<th colspan='2'>{$c}</th>" : "<th colspan='4'>".e($c).'</th>')->implode('').'</tr>'
                ."<tr><th>Cliente</th><th>Reserva</th><th>{$dateLabel}</th><th>Vencimento</th>"
                .collect($currencies)->map(fn ($c) => $c === 'R$' ? '<th>Receita</th><th>Despesa</th>' : '<th>Receita</th><th>BRL</th><th>Despesa</th><th>BRL</th>')->implode('').'</tr>';

            foreach ($carrierBookings->groupBy('cliente_id') as $clientBookings) {
                foreach ($clientBookings as $b) {
                    $html .= '<tr><td>'.e($b->cliente).'</td><td>'.e($b->booking).'</td><td>'.date('d/m/Y', strtotime($b->date)).'</td>'
                        .'<td>'.date('d/m/Y', strtotime($this->dueDate($b, $operation, $boletos->get($b->cliente_id)))).'</td>'
                        .collect($currencies)->map(fn ($c) => $this->cells($forecasts[$b->embarque_id][$c] ?? [], $c))->implode('').'</tr>';
                }
            }
        }

        return [$html.'</table>', $listed->count()];
    }

    /**
     * Previsão por reserva e moeda: despesa e receita, e as duas em R$.
     *
     * @param  list<int>  $ids
     * @param  array<string, float>  $rates
     * @return Collection<int, array<string, array{despesa: float, receita: float, despesa_convertida: float, receita_convertida: float}>>
     */
    private function forecasts(array $ids, string $operation, array $rates): Collection
    {
        $bls = DB::table('bl')->whereIn('embarque_id', $ids)->whereNotNull('mbl_number')->where('mbl_number', '<>', '')->get(['bl_id', 'embarque_id'])->groupBy('embarque_id');
        $containers = DB::table('container')->whereIn('bl_id', $bls->flatten(1)->pluck('bl_id')->all())->selectRaw('bl_id, count(*) as total')->groupBy('bl_id')->pluck('total', 'bl_id');
        $goods = DB::table('mercadoria')->whereIn('embarque_id', $ids)->selectRaw('embarque_id, sum(quantidade) as total')->groupBy('embarque_id')->pluck('total', 'embarque_id');

        $billingToSkip = $operation === 'i' ? 'p' : 'c';

        return DB::table('fin_expenses_values as v')
            ->leftJoin('fin_expenses as x', 'x.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'x.ref_unity')
            ->leftJoin('cmn_currencies as c', 'c.id', '=', 'v.ref_currency_br')
            ->whereIn('v.ref_embarque', $ids)
            ->where('v.ref_due_to', self::DUE_TO)
            ->orderBy('v.id')
            ->get(['v.ref_embarque', 'v.billing', 'v.buying_rate', 'v.selling_rate', 'u.acronym as unity', 'c.symbol'])
            ->reject(fn ($e) => $e->billing === $billingToSkip)
            ->groupBy('ref_embarque')
            ->map(function ($expenses, $id) use ($bls, $containers, $goods, $rates) {
                $totalBls = ($bls[$id] ?? collect())->count();
                $totalContainers = $totalBls > 0 ? $bls[$id]->sum(fn ($bl) => (int) ($containers[$bl->bl_id] ?? 0)) : (int) ($goods[$id] ?? 0);
                $totalBls = max($totalBls, 1);
                $forecast = [];

                foreach ($expenses as $e) {
                    $symbol = (string) $e->symbol;
                    $times = $e->unity === 'BL' ? $totalBls : $totalContainers;
                    $rate = $rates[$symbol] ?? 0.0;
                    $line = $forecast[$symbol] ?? ['despesa' => 0.0, 'receita' => 0.0, 'despesa_convertida' => 0.0, 'receita_convertida' => 0.0];
                    $forecast[$symbol] = [
                        'despesa' => $line['despesa'] + (float) $e->buying_rate * $times,
                        'receita' => $line['receita'] + (float) $e->selling_rate * $times,
                        'despesa_convertida' => $line['despesa_convertida'] + (float) $e->buying_rate * $rate * $times,
                        'receita_convertida' => $line['receita_convertida'] + (float) $e->selling_rate * $rate * $times,
                    ];
                }

                return $forecast;
            });
    }

    /** Embarque::getDataVencimento. */
    private function dueDate(object $booking, string $operation, ?object $boleto): string
    {
        if (! $boleto) {
            return $booking->saida_prevista;
        }

        $term = (int) ($operation === 'i' ? $boleto->prazo_imp : $boleto->prazo_exp);
        $days = $term <= 0 ? 0 : $term - 1;
        $base = $operation === 'i' ? $booking->chegada_prevista : $booking->saida_prevista;

        return Carbon::parse($base)->addDays($days)->toDateString();
    }

    /** @param  array{despesa?: float, receita?: float, despesa_convertida?: float, receita_convertida?: float}  $values */
    private function cells(array $values, string $currency): string
    {
        $cell = fn (string $key) => "<td align='right'>".number_format($values[$key] ?? 0, 2, ',', '.').'</td>';

        return $currency === 'R$'
            ? $cell('receita').$cell('despesa')
            : $cell('receita').$cell('receita_convertida').$cell('despesa').$cell('despesa_convertida');
    }
}
