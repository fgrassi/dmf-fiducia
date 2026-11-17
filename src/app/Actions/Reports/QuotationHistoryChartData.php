<?php

namespace App\Actions\Reports;

/**
 * Dados do gráfico do "Histórico de cotações" (legado): sobre o resumo, reagrupa pelos campos do eixo X (e, opcionalmente, por
 * uma série) e devolve as métricas escolhidas. Mínimo e máximo valem para o grupo todo; a média é ponderada pela quantidade.
 *
 * @phpstan-type Options array{type?: ?string, x_axis?: ?array<int,string>, series?: ?string, metrics?: ?array<int,string>}
 */
class QuotationHistoryChartData
{
    public const METRICS = ['quantidade' => 'Quantidade', 'minimo' => 'Mínimo', 'maximo' => 'Máximo', 'media' => 'Média'];

    public const TYPES = ['barra' => 'Barras', 'linha' => 'Linha'];

    /**
     * Padrão da tela: linha com Mês (ou Ano) no eixo X quando agrupado por período, senão barras com todo o agrupamento; sempre
     * a moeda no eixo X e a média no eixo Y.
     *
     * @param  list<string>  $grouping
     * @return array{type: string, x_axis: list<string>, series: string, metrics: list<string>}
     */
    public static function defaults(array $grouping): array
    {
        $period = in_array('mes', $grouping, true) ? ['mes'] : (in_array('ano', $grouping, true) ? ['ano'] : $grouping);

        return [
            'type' => array_intersect(['mes', 'ano'], $grouping) ? 'linha' : 'barra',
            'x_axis' => [...$period, 'moeda'],
            'series' => '',
            'metrics' => ['media'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $summary  `resumo` de QuotationHistoryReport
     * @param  list<string>  $grouping
     * @param  Options  $options
     * @return array{type: string, categories: list<string>, series: list<array{name: string, values: list<float|int|null>}>}
     */
    public function __invoke(array $summary, array $grouping, array $options = []): array
    {
        $fields = [...$grouping, 'moeda'];
        $xAxis = array_values(array_unique(array_intersect($options['x_axis'] ?? [], $fields))) ?: $grouping;
        $metrics = array_values(array_intersect(array_keys(self::METRICS), $options['metrics'] ?? [])) ?: ['media'];
        $seriesField = in_array($options['series'] ?? '', $fields, true) && ! in_array($options['series'], $xAxis, true) ? $options['series'] : '';
        $type = array_key_exists($options['type'] ?? '', self::TYPES) ? $options['type'] : self::defaults($grouping)['type'];

        $label = fn (array $item, string $field) => $field === 'moeda'
            ? (filled($item['moeda']) ? $item['moeda'] : '-')
            : (filled($value = $item['dimensoes'][array_search($field, $grouping, true)] ?? '') ? $value : '-');

        $cells = [];

        foreach ($summary as $item) {
            $cell = &$cells[implode(' | ', array_map(fn (string $field) => $label($item, $field), $xAxis))][$seriesField === '' ? '' : $label($item, $seriesField)];
            $quantity = (int) $item['quantidade'];
            $cell ??= ['quantidade' => 0, 'minimo' => $item['minimo'], 'maximo' => $item['maximo'], 'soma' => 0.0, 'peso' => 0];
            $cell['quantidade'] += $quantity;
            $cell['minimo'] = min($cell['minimo'], $item['minimo']);
            $cell['maximo'] = max($cell['maximo'], $item['maximo']);
            $cell['soma'] += $item['media'] * max($quantity, 1);
            $cell['peso'] += max($quantity, 1);
            unset($cell);
        }

        $categories = array_map('strval', array_keys($cells));
        usort($categories, $this->compare(...));

        $value = fn (string $category, string $series, string $metric) => isset($cells[$category][$series])
            ? ($metric === 'media' ? round($cells[$category][$series]['soma'] / $cells[$category][$series]['peso'], 2) : $cells[$category][$series][$metric])
            : null;

        if ($seriesField === '') {
            $series = array_map(fn (string $metric) => ['name' => self::METRICS[$metric], 'values' => array_map(fn (string $category) => $value($category, '', $metric), $categories)], $metrics);
        } else {
            $names = array_map('strval', array_unique(array_merge(...array_values(array_map('array_keys', $cells)))));
            usort($names, $this->compare(...));
            $series = array_map(fn (string $name) => ['name' => $name, 'values' => array_map(fn (string $category) => $value($category, $name, $metrics[0]), $categories)], $names);
        }

        return ['type' => $type, 'categories' => $categories, 'series' => $series];
    }

    /** Meses (mm/aaaa) ordenam por ano; o resto, por texto natural sem diferenciar maiúsculas. */
    private function compare(string $a, string $b): int
    {
        $key = fn (string $value) => preg_match('/^(\d{2})\/(\d{4})(.*)$/', trim($value), $month) ? "{$month[2]}-{$month[1]}{$month[3]}" : mb_strtolower(trim($value));

        return strnatcmp($key($a), $key($b));
    }
}
