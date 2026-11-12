<?php

namespace App\Actions\AccountingCovers;

use App\Actions\Booking\ExportQuotationReferences;
use App\Enums\OperationType;
use App\Models\Embarque;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * capasContabilidade/relatorio (legado): relatório de resultado sobre o resumo_resultado, normal e manual.
 *
 * Diferenças do legado: o cache de 1 h dos ids não foi portado (a consulta já é uma só, o botão "Buscar" sempre vê o que está
 * gravado); os subtotais do agrupamento são por grupo e alinhados às colunas (o legado soma por valor do texto, sem olhar o nível, e
 * desalinha as colunas nos subtotais intermediários).
 */
class CoverResultReport
{
    /** Agrupamentos oferecidos (a coluna do resumo_resultado de cada um). O primeiro marcado é a ordenação principal. */
    public const GROUPS = [
        'cliente' => 'Cliente',
        'tipo_embarque' => 'Tipo Embarque',
        'impo_expo' => 'Impo/Expo',
        'mercadoria' => 'Mercadoria',
        'pais_origem' => 'País Origem',
        'porto_origem' => 'Origem',
        'pais_destino' => 'País Destino',
        'porto_destino' => 'Destino',
        'armador' => 'Armador',
        'cia_aerea' => 'Cia Aérea',
        'vendedor' => 'Vendedor',
    ];

    /** Despesas que o relatório separa do resto: sigla => nome. DEM/DET vêm das regras 5 e 6; COU e SEG, das manuais e do seguro. */
    public const CATEGORIES = ['DEM' => 'Demurrage', 'DET' => 'Detention', 'COU' => 'Courier', 'SEG' => 'Seguro'];

    /** Quando a capa cai em mais de uma, vale a primeira desta lista (getCategoriasPorIds). */
    private const PRIORITY = ['SEG', 'DEM', 'DET', 'COU'];

    /** Sem nenhum destes filtros o legado não busca (só mostra os resumos vazios). */
    private const FILTERS = ['booking_id', 'folder_id', 'origin_country_id', 'origin_port_id', 'destination_country_id', 'destination_port_id', 'client_id', 'operation', 'carrier_id', 'airline_id', 'agent_id', 'seller_id', 'type', 'commodity', 'house_bl', 'from', 'until'];

    public static function hasFilter(array $filters): bool
    {
        return collect(self::FILTERS)->contains(fn (string $key) => filled($filters[$key] ?? null));
    }

    /**
     * @param  array<string, mixed>  $filters  chaves de FILTERS
     * @param  list<string>  $groupBy  chaves de GROUPS, na ordem de agrupamento
     * @param  list<string>  $categories  siglas de CATEGORIES; vazio = todas as capas
     * @return array{lines: list<array<string, mixed>>, total: array<string, float>, groups: array<string, array<string, float>>, offices: array<string, array<string, array<string, float>>>, ranking: array<string, float>}
     */
    public function __invoke(array $filters, array $groupBy = [], array $categories = []): array
    {
        $rows = self::hasFilter($filters) ? $this->rows($filters, array_values(array_intersect($groupBy, array_keys(self::GROUPS)))) : collect();
        $rows = $this->withCategories($rows);

        if ($categories !== []) {
            $rows = $rows->filter(fn (stdClass $row) => in_array($row->category, $categories, true))->values();
        }

        return [
            'lines' => $this->lines($rows, array_values(array_intersect($groupBy, array_keys(self::GROUPS)))),
            'total' => $this->sum($rows),
            'groups' => $this->groups($rows),
            'offices' => $this->offices($rows),
            'ranking' => $this->ranking($rows),
        ];
    }

    /** @return Collection<int, stdClass> */
    private function rows(array $filters, array $groupBy): Collection
    {
        $query = DB::table('resumo_resultado as r')
            ->leftJoin('capa_processo as cp', 'cp.capa_process_id', '=', 'r.capa_processo_id')
            ->leftJoin('capa_processo_manual as cpm', 'cpm.capa_processo_manual_id', '=', 'r.capa_processo_manual_id')
            ->leftJoin('fin_expenses as fe', 'fe.id', '=', 'cpm.ref_expense')
            ->leftJoin('sedes as s', 's.id', '=', 'r.centro_custo_id')
            ->leftJoin('embarque as e', 'e.embarque_id', '=', 'r.embarque_id')
            ->leftJoin('cotacao_impo as qi', 'qi.cotacao_impo_id', '=', 'e.cotacao_impo_id')
            ->leftJoin('cotacao_impo_aereo as qa', 'qa.cotacao_impo_aereo_id', '=', 'e.cotacao_impo_aereo_id')
            ->leftJoin('pessoa as agent', 'agent.pessoa_id', '=', 'e.pessoa_id_representante')
            ->leftJoin('pessoa as person', 'person.pessoa_id', '=', 'r.cliente_id')
            ->select([
                'r.*',
                'cp.numero as cover_number',
                'cp.custo_financeiro as financial_cost',
                'cp.receitas as cover_revenue',
                'cp.comissoes as cover_commission',
                'cp.valor_cotacao as cover_rate',
                'cpm.moeda_usd_eur as manual_currency',
                'cpm.valor_usd_eur as manual_value',
                'cpm.valor_cotacao as manual_rate',
                'cpm.ref_expense as manual_expense_id',
                'cpm.nome_cliente as manual_client',
                'fe.operation_type as expense_operation',
                's.nome as office',
                'e.de as closer',
                'e.impor_expor as booking_operation',
                'e.tipo_embarque as booking_type',
                'e.cotacao_expo_id',
                'qi.referencia as import_quotation',
                'qa.referencia as air_quotation',
                'agent.nome as agent',
                'person.nome as person',
            ])
            ->when($filters['booking_id'] ?? null, fn ($q, $id) => $q->where('r.embarque_id', $id))
            ->when($filters['folder_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('cp.capa_processo_arquivo_id', $id)->orWhere('cpm.capa_processo_arquivo_id', $id)))
            ->when($filters['origin_country_id'] ?? null, fn ($q, $id) => $q->where('r.pais_origem_id', $id))
            ->when($filters['origin_port_id'] ?? null, fn ($q, $id) => $q->where('r.porto_origem_id', $id))
            ->when($filters['destination_country_id'] ?? null, fn ($q, $id) => $q->where('r.pais_destino_id', $id))
            ->when($filters['destination_port_id'] ?? null, fn ($q, $id) => $q->where('r.porto_destino_id', $id))
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('r.cliente_id', $id))
            ->when($filters['operation'] ?? null, fn ($q, $operation) => $q->where('r.impo_expo', $operation))
            ->when($filters['carrier_id'] ?? null, fn ($q, $id) => $q->where('r.armador_id', $id))
            ->when($filters['airline_id'] ?? null, fn ($q, $id) => $q->where('r.cia_aerea_id', $id))
            ->when($filters['agent_id'] ?? null, fn ($q, $id) => $q->where('e.pessoa_id_representante', $id))
            ->when($filters['seller_id'] ?? null, fn ($q, $id) => $q->where('r.vendedor_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('r.tipo_embarque', $type))
            ->when($filters['commodity'] ?? null, fn ($q, $text) => $q->where('r.mercadoria', 'like', "%{$text}%"))
            ->when($filters['house_bl'] ?? null, fn ($q, $text) => $q->whereRaw('upper(r.house_bl) like ?', ['%'.strtoupper($text).'%']))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('r.data_pagamento', '>=', $date))
            ->when($filters['until'] ?? null, fn ($q, $date) => $q->where('r.data_pagamento', '<=', $date));

        foreach ($groupBy as $group) {
            $query->orderBy("r.{$group}");
        }

        $rows = $query->orderBy('cp.numero')->orderBy('r.resumo_resultado_id')->get()->map(fn (stdClass $row) => $this->withValues($row));
        $exports = app(ExportQuotationReferences::class)($rows->pluck('cotacao_expo_id')->all());

        return $rows->each(fn (stdClass $row) => $row->quotation = $this->quotation($row, $exports));
    }

    /**
     * Embarque::getCotacao (legado): importação marítima e aérea usam a referência gravada, a exportação a montada; o resto é N/D.
     * Capa manual não tem reserva.
     *
     * @param  array<int, string>  $exports
     */
    private function quotation(stdClass $row, array $exports): string
    {
        if (! $row->capa_processo_id) {
            return '';
        }

        $reference = match (true) {
            $row->booking_operation !== 'i' => $exports[$row->cotacao_expo_id] ?? null,
            $row->booking_type === 'm' => $row->import_quotation,
            $row->booking_type === 'a' => $row->air_quotation,
            default => null,
        };

        return filled($reference) ? $reference : 'N/D';
    }

    /**
     * calcularValoresLinhaRelatorio (legado). Na capa normal as taxas só contam na moeda que tem valor e o custo financeiro entra com
     * o sinal trocado; na manual a taxa é o lucro em USD/EUR pela cotação.
     */
    private function withValues(stdClass $row): stdClass
    {
        $row->usd = (float) $row->usd;
        $row->euro = (float) $row->euro;
        $row->rs = (float) $row->rs;
        $row->tx_usd = 0.0;
        $row->tx_euro = 0.0;
        $row->financial_cost = 0.0 - (float) $row->financial_cost;

        if ($row->capa_processo_id) {
            $rate = ((float) $row->cover_revenue + (float) $row->cover_commission) * (float) $row->cover_rate;
            $row->tx_usd = $row->usd != 0 ? $rate : 0.0;
            $row->tx_euro = $row->euro != 0 ? $rate : 0.0;
        } else {
            $row->financial_cost = 0.0;
            $converted = (float) $row->manual_value * (float) $row->manual_rate;
            $row->tx_usd = $row->manual_currency == 2 ? $converted : 0.0;
            $row->tx_euro = $row->manual_currency == 3 ? $converted : 0.0;
        }

        $row->result = $row->rs + $row->tx_usd + $row->tx_euro + $row->financial_cost;
        $row->client = $row->capa_processo_id ? $row->cliente : ($row->person ?: $row->manual_client);
        $row->operation = in_array($row->impo_expo, ['i', 'e'], true) ? $row->impo_expo : 'i';
        $row->office_name = filled($row->office) ? $row->office : (filled($row->centro_custo) ? $row->centro_custo : 'N/D');

        return $row;
    }

    /**
     * getCategoriasPorIds (legado): DEM/DET pelas regras 5 e 6 nos itens da capa; capa manual pela regra ou pelo nome da despesa;
     * SEG também para reservas de seguro. Guarda em `category` a que vale e em `categories` todas (os grupos somam em cada uma).
     *
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, stdClass>
     */
    private function withCategories(Collection $rows): Collection
    {
        $covers = $this->coverCategories($rows->pluck('capa_processo_id')->filter()->all());
        $manuals = $this->manualCategories($rows->pluck('capa_processo_manual_id')->filter()->all());

        return $rows->each(function (stdClass $row) use ($covers, $manuals) {
            $found = array_merge(
                $covers[$row->capa_processo_id] ?? [],
                $manuals[$row->capa_processo_manual_id] ?? [],
                $row->tipo_embarque === 's' ? ['SEG'] : [],
            );
            $row->categories = array_values(array_unique($found));
            $row->category = collect(self::PRIORITY)->first(fn (string $category) => in_array($category, $row->categories, true));
        });
    }

    /** @return array<int, list<string>> capa => siglas */
    private function coverCategories(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $query = fn (string $items) => DB::table("{$items} as i")
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.fin_expenses_values_id')
            ->join('regra_despesa_cobranca_item as ri', 'ri.despesa_id', '=', 'v.ref_expense')
            ->whereIn('ri.regra_despesa_cobranca_id', [5, 6])
            ->whereIn('i.capa_processo_id', $ids)
            ->select('i.capa_processo_id as id', 'ri.regra_despesa_cobranca_id as rule');

        return $query('capa_processo_despesa_item')->union($query('capa_processo_receita_item'))->get()
            ->groupBy('id')
            ->map(fn (Collection $rules) => $rules->map(fn (stdClass $rule) => $rule->rule == 5 ? 'DEM' : 'DET')->all())
            ->all();
    }

    /** @return array<int, list<string>> capa manual => siglas */
    private function manualCategories(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('capa_processo_manual as m')
            ->join('fin_expenses as fe', 'fe.id', '=', 'm.ref_expense')
            ->leftJoin('regra_despesa_cobranca_item as ri', fn ($join) => $join->on('ri.despesa_id', '=', 'm.ref_expense')->whereIn('ri.regra_despesa_cobranca_id', [5, 6, 12, 13, 14, 15]))
            ->whereIn('m.capa_processo_manual_id', $ids)
            ->get(['m.capa_processo_manual_id as id', 'ri.regra_despesa_cobranca_id as rule', 'fe.name'])
            ->groupBy('id')
            ->map(fn (Collection $items) => $items->map(function (stdClass $item) {
                $name = mb_strtoupper((string) $item->name);

                return match (true) {
                    $item->rule == 5 => 'DEM',
                    $item->rule == 6 => 'DET',
                    in_array($item->rule, [12, 13]) || str_contains($name, 'COURIER') => 'COU',
                    in_array($item->rule, [14, 15]) || str_contains($name, 'SEGURO') => 'SEG',
                    default => null,
                };
            })->filter()->all())
            ->all();
    }

    /**
     * As linhas na ordem do relatório, com a de subtotal ("TOTAL <valor>") quando um nível de agrupamento muda: ao mudar um nível,
     * fecham-se os de baixo primeiro.
     *
     * @param  Collection<int, stdClass>  $rows
     * @param  list<string>  $groupBy
     * @return list<array<string, mixed>>
     */
    private function lines(Collection $rows, array $groupBy): array
    {
        $lines = [];
        $current = [];
        $sums = array_map(fn () => $this->sum(collect()), $groupBy);

        $close = function (int $from) use (&$lines, &$current, &$sums, $groupBy) {
            for ($level = count($groupBy) - 1; $level >= $from; $level--) {
                $lines[] = ['subtotal' => $this->label($groupBy[$level], $current[$level]), 'sum' => $sums[$level], 'level' => $level];
                $sums[$level] = $this->sum(collect());
            }
        };

        foreach ($rows as $row) {
            $values = array_map(fn (string $group) => filled($row->{$group}) ? (string) $row->{$group} : 'N/D', $groupBy);

            if ($current !== []) {
                $changed = collect($values)->search(fn (string $value, int $level) => $value !== $current[$level]);

                if ($changed !== false) {
                    $close($changed);
                }
            }

            $current = $values;
            $lines[] = ['row' => $row];

            foreach ($sums as $level => $sum) {
                $sums[$level] = $this->add($sum, $row);
            }
        }

        if ($current !== []) {
            $close(0);
        }

        return $lines;
    }

    private function label(string $group, string $value): string
    {
        return 'TOTAL '.match ($group) {
            'tipo_embarque' => mb_strtoupper(Embarque::TYPES[$value] ?? $value),
            'impo_expo' => mb_strtoupper(OperationType::labelFor($value)),
            default => $value,
        };
    }

    /** @param  Collection<int, stdClass>  $rows */
    private function sum(Collection $rows): array
    {
        return $rows->reduce(fn (array $sum, stdClass $row) => $this->add($sum, $row), array_fill_keys(['usd', 'tx_usd', 'rs', 'euro', 'tx_euro', 'financial_cost', 'result'], 0.0));
    }

    private function add(array $sum, stdClass $row): array
    {
        foreach ($sum as $key => $value) {
            $sum[$key] = $value + $row->{$key};
        }

        return $sum;
    }

    /**
     * getResumoDespesasPorGrupo (legado): resultado das capas de cada despesa separada, por Impo/Expo. A capa manual sem Impo/Expo
     * usa o da despesa.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, array<string, float>>
     */
    private function groups(Collection $rows): array
    {
        $groups = array_map(fn () => ['i' => 0.0, 'e' => 0.0], self::CATEGORIES);

        foreach ($rows as $row) {
            $operation = in_array($row->impo_expo, ['i', 'e'], true) ? $row->impo_expo : (in_array($row->expense_operation, ['i', 'e'], true) ? $row->expense_operation : 'i');

            foreach ($row->categories as $category) {
                $groups[$category][$operation] += $row->result;
            }
        }

        return $groups;
    }

    /**
     * montarResumoPorEscritorio (legado): por Impo/Expo e escritório, as moedas e o resultado de cada despesa separada; o que não é
     * uma delas entra em `rs`.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, array<string, array<string, float>>>
     */
    private function offices(Collection $rows): array
    {
        $offices = ['e' => [], 'i' => []];
        $empty = array_fill_keys(['usd', 'tx_usd', 'rs', 'euro', 'tx_euro', ...array_keys(self::CATEGORIES)], 0.0);

        foreach ($rows as $row) {
            $office = &$offices[$row->operation][$row->office_name];
            $office ??= $empty;
            $office['usd'] += $row->usd;
            $office['tx_usd'] += $row->tx_usd;
            $office['euro'] += $row->euro;
            $office['tx_euro'] += $row->tx_euro;
            $office[$row->category ?? 'rs'] += $row->category ? $row->result : $row->rs;
            unset($office);
        }

        ksort($offices['e']);
        ksort($offices['i']);

        return $offices;
    }

    /**
     * montarRankingClientes (legado): resultado por cliente, do maior para o menor.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, float>
     */
    private function ranking(Collection $rows): array
    {
        return $rows->groupBy(fn (stdClass $row) => filled($row->client) ? $row->client : 'N/D')
            ->map(fn (Collection $client) => $client->sum('result'))
            ->sortDesc()
            ->all();
    }
}
