<?php

namespace App\Actions\Financeiro;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * cotacao/index (legado), "Consulta Cotação Diária": matriz data × cotação com o último valor lançado em cada dia
 * (`created_at` do lançamento, não `rate_date`, como o legado), das cotações e depois das taxas de armador. Datas da mais
 * nova para a mais antiga.
 */
class DailyRatesReport
{
    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $input
     * @return array{columns: list<array{name: string, currency: string}>, rows: list<array{date: string, values: list<?float>}>}
     */
    public function __invoke(array $input): array
    {
        $ini = Carbon::parse($input['data_ini'])->startOfDay();
        $fim = Carbon::parse($input['data_fim'])->startOfDay();

        $columns = [];
        $values = [];

        foreach ([
            ['fin_currencies_rates', 'fin_currencies_rates_values', 'ref_currency_rate', 'name'],
            ['fin_currencies_rates_armador', 'fin_currencies_rates_armador_values', 'ref_currency_rate_armador', 'armador.nome'],
        ] as [$ratesTable, $valuesTable, $key, $nameColumn]) {
            $rates = DB::table("$ratesTable as r")
                ->leftJoin('cmn_currencies as c', 'c.id', '=', 'r.ref_currency')
                ->when($nameColumn !== 'name', fn ($query) => $query->leftJoin('armador', 'armador.armador_id', '=', 'r.ref_armador'))
                ->orderBy('r.id')
                ->get(['r.id', DB::raw(($nameColumn === 'name' ? 'r.name' : $nameColumn).' as rate_name'), 'c.name as currency_name', 'c.symbol']);

            // o último lançamento do dia (maior id) vale: o primeiro de cada cotação/data na ordem decrescente
            $latest = [];
            foreach (DB::table($valuesTable)->whereBetween('created_at', [$ini->toDateString(), $fim->toDateString()])->whereIn($key, $rates->pluck('id'))->orderByDesc('id')->get([$key, 'created_at', 'rate']) as $value) {
                $latest[$value->$key][substr($value->created_at, 0, 10)] ??= (float) $value->rate;
            }

            foreach ($rates as $rate) {
                $columns[] = ['name' => (string) $rate->rate_name, 'currency' => $rate->currency_name === null ? '' : "{$rate->currency_name} ({$rate->symbol})"];
                $values[] = $latest[$rate->id] ?? [];
            }
        }

        $rows = [];
        for ($day = $fim->copy(); $day >= $ini; $day->subDay()) {
            $date = $day->toDateString();
            $rows[] = ['date' => $date, 'values' => array_map(fn (array $byDate) => $byDate[$date] ?? null, $values)];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
