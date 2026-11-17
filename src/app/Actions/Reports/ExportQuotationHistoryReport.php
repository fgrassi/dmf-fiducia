<?php

namespace App\Actions\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * relatorioDespesasCotacao/excel (legado): a aba "Resumo" (agrupamento, moeda, quantidade, mínimo, máximo e média) e, se pedido,
 * a aba "Listagem" com uma linha por despesa de cotação e/ou o gráfico (dados na aba "Grafico", desenho na aba "Resumo").
 */
class ExportQuotationHistoryReport
{
    private const LIST_HEADERS = ['Cotacao', 'Cadastro', 'Validade', 'Cliente', 'Origem', 'Destino', 'Despesa', 'Equipamento', 'Moeda', 'Valor'];

    /**
     * @param  array<string, mixed>  $report  saída de QuotationHistoryReport
     * @param  array<string, mixed>|null  $chart  saída de QuotationHistoryChartData, para incluir o gráfico
     */
    public function __invoke(array $report, bool $withList, ?array $chart = null): StreamedResponse
    {
        $book = $this->build($report, $withList, $chart);
        $name = 'historico_cotacoes_'.str($report['tipo_label'])->ascii()->snake().'_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($book))->setIncludeCharts($chart !== null)->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>|null  $chart
     */
    public function build(array $report, bool $withList, ?array $chart = null): Spreadsheet
    {
        $date = fn (?string $value) => filled($value) && ($time = strtotime($value)) ? date('d/m/Y', $time) : '';
        $groupHeaders = array_map(fn (string $field) => QuotationHistoryReport::DIMENSIONS[$field], $report['agrupamento']);

        $book = new Spreadsheet;
        $summary = $book->getActiveSheet()->setTitle('Resumo');
        $summary->fromArray([
            [...$groupHeaders, 'Moeda', 'Quantidade', 'Minimo', 'Maximo', 'Media'],
            ...array_map(fn (array $row) => [...$row['dimensoes'], $row['moeda'], $row['quantidade'], round($row['minimo'], 2), round($row['maximo'], 2), $row['media']], $report['resumo']),
        ]);

        $first = count($groupHeaders) + 4;
        $summary->getStyle([$first, 2, $first + 2, count($report['resumo']) + 1])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $this->finish($summary, count($groupHeaders) + 5);

        if ($chart !== null && $chart['categories'] !== []) {
            $this->addChart($summary, $chart, count($groupHeaders) + 7);
        }

        if ($withList) {
            $list = $book->createSheet()->setTitle('Listagem');
            $list->fromArray([
                self::LIST_HEADERS,
                ...array_map(fn (array $row) => [
                    $row['cotacao_id'].(filled($row['referencia']) ? " - {$row['referencia']}" : ''), $date($row['data_cadastro']), $date($row['validade']),
                    $row['cliente'], $row['origem'], $row['destino'], $row['despesa_nome'], $row['equipamento'], $row['moeda_venda'], $row['valor'],
                ], $report['rows']),
            ]);
            $this->finish($list, count(self::LIST_HEADERS));
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** Dados na aba "Grafico" (categoria e uma coluna por série) e o gráfico desenhado ao lado da tabela do resumo. */
    private function addChart(Worksheet $summary, array $chart, int $column): void
    {
        $data = $summary->getParent()->createSheet()->setTitle('Grafico');
        $data->fromArray([['Categoria', ...array_column($chart['series'], 'name')], ...array_map(fn (string $category, int $index) => [$category, ...array_map(fn (array $series) => $series['values'][$index] === null ? null : round($series['values'][$index], 2), $chart['series'])], $chart['categories'], array_keys($chart['categories']))]);
        $this->finish($data, count($chart['series']) + 1);

        $points = count($chart['categories']);
        $last = $points + 1;
        $columns = array_map(fn (int $index) => Coordinate::stringFromColumnIndex($index + 2), array_keys($chart['series']));
        $line = $chart['type'] === 'linha';

        $plot = new DataSeries(
            $line ? DataSeries::TYPE_LINECHART : DataSeries::TYPE_BARCHART,
            $line ? DataSeries::GROUPING_STANDARD : DataSeries::GROUPING_CLUSTERED,
            array_keys($chart['series']),
            array_map(fn (string $letter, array $series) => new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Grafico'!\${$letter}\$1", null, 1, [$series['name']]), $columns, $chart['series']),
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Grafico'!\$A\$2:\$A\${$last}", null, $points, $chart['categories'])],
            array_map(fn (string $letter, array $series) => new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Grafico'!\${$letter}\$2:\${$letter}\${$last}", NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, $points, array_map(fn ($value) => $value === null ? 0 : round($value, 2), $series['values'])), $columns, $chart['series']),
        );
        $line || $plot->setPlotDirection(DataSeries::DIRECTION_COL);

        $drawing = new Chart('grafico_resumo', new Title('Histórico de cotações'), new Legend(Legend::POSITION_RIGHT, null, false), new PlotArea(null, [$plot]), true, DataSeries::EMPTY_AS_GAP, null, new Title('Valor'));
        $drawing->setTopLeftPosition(Coordinate::stringFromColumnIndex($column).'1');
        $drawing->setBottomRightPosition(Coordinate::stringFromColumnIndex($column + 12).max(18, min(42, $points + 10)));
        $summary->addChart($drawing);
    }

    private function finish(Worksheet $sheet, int $columns): void
    {
        $sheet->getStyle([1, 1, $columns, 1])->getFont()->setBold(true);

        foreach (range(1, $columns) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
    }
}
