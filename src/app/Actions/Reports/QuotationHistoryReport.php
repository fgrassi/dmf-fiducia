<?php

namespace App\Actions\Reports;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * relatorioDespesasCotacao (legado), "Histórico de cotações": os valores de venda das despesas das cotações (Impo marítima,
 * Impo aérea ou Expo) num período de cadastro ou de validade, agrupados por origem, destino, ano, mês e/ou equipamento, com
 * quantidade, mínimo, máximo e média por moeda. Itens de valor zero não entram no resumo, só na lista.
 *
 * @phpstan-type Filters array{data_ini:?string, data_fim:?string, tipo_data:?string, tipo:?string, modal:?string, tipo_carga:?string, cliente_id:int|string|null, pais_origem_ids:?array<int,int|string>, porto_origem_ids:?array<int,int|string>, pais_destino_ids:?array<int,int|string>, porto_destino_ids:?array<int,int|string>, despesa_ids:?array<int,int|string>, regra_ids:?array<int,int|string>, equipamento_ids:?array<int,int|string>, agrupamento:?array<int,string>}
 */
class QuotationHistoryReport
{
    public const DIMENSIONS = ['origem' => 'Origem', 'destino' => 'Destino', 'ano' => 'Ano', 'mes' => 'Mês', 'tipo_equipamento' => 'Tipo de equipamento', 'equipamento' => 'Equipamento'];

    public const DATE_AXES = ['cadastro' => 'Data de cadastro', 'validade' => 'Validade'];

    /** [tabela da cotação, tabela dos itens, chave, validade, referência, tela da cotação no tracking] por tipo. */
    private const SOURCES = [
        'impo' => ['cotacao_impo', 'cotacao_impo_item', 'cotacao_impo_id', 'COALESCE(q.validade_max, q.validade)', 'q.referencia', 'cotacaoImpo/show'],
        'impo_aereo' => ['cotacao_impo_aereo', 'cotacao_impo_aereo_item', 'cotacao_impo_aereo_id', 'COALESCE(q.validade_max, q.validade)', 'q.referencia', 'cotacaoImpoAereo/index'],
        'expo' => ['cotacao_expo', 'cotacao_expo_item', 'cotacao_expo_id', 'q.validade', 'NULL', 'cotacaoExpo/show'],
    ];

    private const LABELS = ['impo' => 'Impo', 'impo_aereo' => 'Impo Aéreo', 'expo' => 'Expo'];

    /**
     * @param  Filters  $input
     * @return array{tipo_label: string, periodo: string, agrupamento: list<string>, registros: int, rows: list<array<string, mixed>>, resumo: list<array<string, mixed>>}
     *
     * @throws DomainException filtro inválido (mesmas mensagens do legado)
     */
    public function __invoke(array $input): array
    {
        $filters = $this->parse($input);
        $rows = $this->query($filters)->get()->map(fn (object $row) => $this->row((array) $row) + ['cotacao_path' => self::SOURCES[$filters['source']][5]])->all();

        return [
            'tipo_label' => self::LABELS[$filters['source']],
            'periodo' => $filters['from']->format('d/m/Y').' até '.$filters['to']->format('d/m/Y'),
            'agrupamento' => $filters['grouping'],
            'registros' => count($rows),
            'rows' => $rows,
            'resumo' => $this->summary($rows, $filters),
        ];
    }

    /**
     * @param  Filters  $input
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    private function parse(array $input): array
    {
        $date = fn (?string $value) => filled($value) && Carbon::canBeCreatedFromFormat($value, 'Y-m-d') ? CarbonImmutable::parse($value) : null;
        $ids = fn (string $key) => array_values(array_unique(array_filter(array_map('intval', $input[$key] ?? []))));
        [$from, $to] = [$date($input['data_ini'] ?? null), $date($input['data_fim'] ?? null)];

        throw_unless($from && $to, DomainException::class, 'Informe a data inicial e a data final.');
        throw_if($from > $to, DomainException::class, 'A data inicial deve ser anterior ou igual à data final.');
        throw_unless(in_array($type = $input['tipo'] ?? '', ['impo', 'expo'], true), DomainException::class, 'Selecione o tipo Impo ou Expo.');
        throw_unless(in_array($modal = $input['modal'] ?? '', ['maritimo', 'aereo'], true), DomainException::class, 'Selecione o modal Marítimo ou Aéreo.');
        throw_if($type === 'expo' && $modal === 'aereo', DomainException::class, 'Não há cotações aéreas de Expo. Selecione Marítimo ou altere o tipo para Impo.');

        $filters = [
            'from' => $from,
            'to' => $to,
            'source' => $type === 'expo' ? 'expo' : ($modal === 'aereo' ? 'impo_aereo' : 'impo'),
            'axis' => ($input['tipo_data'] ?? null) === 'validade' ? 'validade' : 'cadastro',
            'cargo' => $modal === 'maritimo' && in_array($input['tipo_carga'] ?? null, ['fcl', 'lcl'], true) ? $input['tipo_carga'] : null,
            'client' => (int) ($input['cliente_id'] ?? 0),
            'origin_countries' => $ids('pais_origem_ids'),
            'origin_ports' => $ids('porto_origem_ids'),
            'destination_countries' => $ids('pais_destino_ids'),
            'destination_ports' => $ids('porto_destino_ids'),
            'expenses' => $ids('despesa_ids'),
            'rules' => $ids('regra_ids'),
            'equipment' => $ids('equipamento_ids'),
            'grouping' => array_values(array_intersect(array_keys(self::DIMENSIONS), $input['agrupamento'] ?? [])) ?: ['equipamento'],
        ];

        throw_unless($filters['origin_countries'] || $filters['origin_ports'] || $filters['destination_countries'] || $filters['destination_ports'], DomainException::class, 'Informe país ou porto de origem, ou país ou porto de destino.');
        throw_unless($filters['expenses'] || $filters['rules'], DomainException::class, 'Informe a despesa ou o grupo de despesas.');

        return $filters;
    }

    /** @param  array<string, mixed>  $filters */
    private function query(array $filters): Builder
    {
        [$quotation, $items, $key, $validity, $reference] = self::SOURCES[$filters['source']];
        $dateField = $filters['axis'] === 'validade' ? $validity : 'q.created_at';

        return DB::table("{$items} as i")
            ->join("{$quotation} as q", "q.{$key}", '=', "i.{$key}")
            ->join('fin_expenses as fe', 'fe.id', '=', 'i.despesa_id')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'q.pessoa_id')
            ->leftJoin('porto as po', 'po.porto_id', '=', 'q.porto_origem_id')
            ->leftJoin('paises as pao', 'pao.paises_id', '=', 'po.ref_pais')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'q.porto_destino_id')
            ->leftJoin('paises as pad', 'pad.paises_id', '=', 'pd.ref_pais')
            ->leftJoin('tipo_container as tc', 'tc.tipo_container_id', '=', 'i.tipo_container_id')
            ->leftJoin('cmn_currencies as mv', 'mv.id', '=', 'i.moeda_venda_id')
            ->selectRaw("q.{$key} as cotacao_id, DATE(q.created_at) as data_cadastro, {$validity} as validade, {$reference} as referencia,
                p.nome as cliente_nome, p.cnpj as cliente_cnpj, po.nome as origem_nome, po.uncode as origem_uncode, pao.nome as pais_origem,
                pd.nome as destino_nome, pd.uncode as destino_uncode, pad.nome as pais_destino, fe.name as despesa_nome, i.valor_venda,
                mv.symbol as moeda_venda, COALESCE(tc.nome, '') as equipamento, COALESCE(tc.tipo, '') as equipamento_tipo")
            ->whereRaw("{$dateField} >= ?", [$filters['from']->toDateString()])
            ->whereRaw("{$dateField} < ?", [$filters['to']->addDay()->toDateString()])
            ->when($filters['client'], fn (Builder $query, int $client) => $query->where('q.pessoa_id', $client))
            ->when($filters['origin_countries'], fn (Builder $query, array $ids) => $query->whereIn('po.ref_pais', $ids))
            ->when($filters['origin_ports'], fn (Builder $query, array $ids) => $query->whereIn('q.porto_origem_id', $ids))
            ->when($filters['destination_countries'], fn (Builder $query, array $ids) => $query->whereIn('pd.ref_pais', $ids))
            ->when($filters['destination_ports'], fn (Builder $query, array $ids) => $query->whereIn('q.porto_destino_id', $ids))
            ->where(fn (Builder $query) => $query
                ->when($filters['expenses'], fn (Builder $query, array $ids) => $query->orWhereIn('fe.id', $ids))
                ->when($filters['rules'], fn (Builder $query, array $ids) => $query->orWhereIn('fe.id', DB::table('regra_despesa_cobranca_item')->select('despesa_id')->whereIn('regra_despesa_cobranca_id', $ids))))
            ->when($filters['equipment'], fn (Builder $query, array $ids) => $query->whereIn('i.tipo_container_id', $ids), fn (Builder $query) => $query->when($filters['cargo'], fn (Builder $query, string $cargo) => $query->whereIn('i.tipo_container_id', DB::table('tipo_container')->select('tipo_container_id')
                ->when($cargo === 'lcl', fn (Builder $query) => $query->where('tipo', 'lcl'), fn (Builder $query) => $query->where('tipo_embarque', 'm')->where('tipo', '<>', 'lcl')))))
            ->orderByDesc('q.created_at')
            ->orderBy('p.nome')
            ->orderBy('fe.name')
            ->orderBy('tc.nome');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $place = fn (string $prefix) => collect([
            trim(($row["{$prefix}_nome"] ?? '').(filled($row["{$prefix}_uncode"]) ? " ({$row["{$prefix}_uncode"]})" : '')),
            $row[$prefix === 'origem' ? 'pais_origem' : 'pais_destino'],
        ])->filter()->implode(' / ');

        return $row + [
            'origem' => $place('origem'),
            'destino' => $place('destino'),
            'cliente' => $row['cliente_nome'].(filled($row['cliente_cnpj']) ? " ({$row['cliente_cnpj']})" : ''),
        ] + ['valor' => (float) $row['valor_venda']];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function summary(array $rows, array $filters): array
    {
        $groups = [];

        foreach ($rows as $row) {
            if ($row['valor'] == 0) {
                continue;
            }

            $dimensions = array_map(fn (string $field) => $this->dimension($row, $field, $filters['axis']), $filters['grouping']);
            $currency = filled($row['moeda_venda']) ? $row['moeda_venda'] : '-';
            $groups[implode("\x1F", [...$dimensions, $currency])][] = [$dimensions, $currency, $row['valor']];
        }

        $summary = array_map(fn (array $values) => [
            'dimensoes' => $values[0][0],
            'moeda' => $values[0][1],
            'quantidade' => count($values),
            'minimo' => min(array_column($values, 2)),
            'maximo' => max(array_column($values, 2)),
            'media' => round(array_sum(array_column($values, 2)) / count($values), 2),
        ], array_values($groups));

        usort($summary, fn (array $a, array $b) => [...array_map($this->sortKey(...), $a['dimensoes']), $a['moeda']] <=> [...array_map($this->sortKey(...), $b['dimensoes']), $b['moeda']]);

        return $summary;
    }

    /** @param  array<string, mixed>  $row */
    private function dimension(array $row, string $field, string $axis): string
    {
        $date = $axis === 'validade' ? $row['validade'] : $row['data_cadastro'];

        return match ($field) {
            'origem', 'destino', 'equipamento' => filled($row[$field]) ? $row[$field] : '-',
            'tipo_equipamento' => match (strtolower(trim($row['equipamento_tipo']))) {
                '20' => '20',
                '40' => '40',
                'lcl' => 'LCL',
                default => 'Outros',
            },
            'ano' => filled($date) && ($time = strtotime($date)) ? date('Y', $time) : '-',
            'mes' => filled($date) && ($time = strtotime($date)) ? date('m/Y', $time) : '-',
        };
    }

    /** Meses (mm/aaaa) ordenam por ano; o resto, por texto sem diferenciar maiúsculas. */
    private function sortKey(string $value): string
    {
        return preg_match('/^(\d{2})\/(\d{4})$/', $value, $month) ? "{$month[2]}-{$month[1]}" : mb_strtolower($value);
    }
}
