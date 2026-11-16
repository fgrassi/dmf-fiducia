<?php

namespace App\Actions\Contabilidade;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Botão "Exportar XLS" dos relatórios de contabilidade (legado): a tabela da tela com a linha de total no rodapé. */
class ExportAccountingReport
{
    private const MONEY = '#,##0.00';

    /**
     * @param  array<string, array{0: string, 1: 'text'|'date'|'money'}>  $columns  coluna => [título, tipo]
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, float>  $totals  coluna => total
     */
    public function __invoke(string $title, array $columns, array $rows, array $totals): StreamedResponse
    {
        return response()->streamDownload(fn () => (new Xlsx($this->build($title, $columns, $rows, $totals)))->save('php://output'), str($title)->ascii()->slug('_').'_'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array<string, array{0: string, 1: string}>  $columns */
    public function build(string $title, array $columns, array $rows, array $totals): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('DMF Logistics do Brasil')->setTitle($title);
        $sheet = $book->getActiveSheet()->setTitle(mb_substr(str_replace(['/', '\\', '?', '*', ':', '[', ']'], '-', $title), 0, 31));
        $keys = array_keys($columns);
        $last = count($keys);

        $sheet->fromArray(array_map(fn (array $column) => $column[0], array_values($columns)), null, 'A1');
        $sheet->getStyle([1, 1, $last, 1])->getFont()->setBold(true);

        foreach ($rows as $y => $row) {
            foreach ($keys as $x => $key) {
                $value = $row[$key];
                $sheet->setCellValue([$x + 1, $y + 2], $columns[$key][1] === 'date' && filled($value) ? Carbon::parse($value)->format('d/m/Y') : $value);
            }
        }

        $footer = count($rows) + 2;
        $firstTotal = min(array_map(fn (string $key) => array_search($key, $keys, true), array_keys($totals)));
        $sheet->setCellValue([$firstTotal, $footer], 'Total');

        foreach ($totals as $key => $total) {
            $sheet->setCellValue([array_search($key, $keys, true) + 1, $footer], $total);
        }

        $sheet->getStyle([1, $footer, $last, $footer])->getFont()->setBold(true);

        foreach ($keys as $x => $key) {
            if ($columns[$key][1] === 'money') {
                $sheet->getStyle([$x + 1, 2, $x + 1, $footer])->getNumberFormat()->setFormatCode(self::MONEY);
            }

            $sheet->getColumnDimensionByColumn($x + 1)->setAutoSize(true);
        }

        return $book;
    }
}
