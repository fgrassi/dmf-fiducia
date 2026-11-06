<?php

namespace App\Actions\Comissoes;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel do Relatório Comissões de Embarque: as mesmas colunas da tela, com a linha de total. */
class ExportShippingCommissionsReport
{
    private const HEADERS = ['Comissionado', 'Booking', 'Tipo', 'Tipo de comissão', 'Moeda', 'Nº de containers', 'Valor unitário', 'Total de pagamentos (R$)', 'Taxa', 'Data do recibo', 'Status', 'Observação'];

    private const MONEY = '#,##0.00';

    /** @param  array{rows: list<array<string, mixed>>, totais: array{containers:int, pago:float}}  $report  saída de ShippingCommissionsReport */
    public function __invoke(array $report): StreamedResponse
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('DMF Logistics do Brasil')->setTitle('Comissões de Embarque');
        $sheet = $book->getActiveSheet()->setTitle('Comissões');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $last = count(self::HEADERS);

        foreach ($report['rows'] as $y => $row) {
            $line = $y + 2;
            $text = [1 => $row['responsavel'], 2 => $row['booking'], 3 => $row['tipo'], 4 => $row['tipo_comissao'], 5 => $row['moeda'], 10 => $this->dates($row['datas_recibo']), 11 => $row['status'], 12 => $row['obs']];

            foreach ($text as $column => $value) {
                $sheet->setCellValueExplicit([$column, $line], $value, DataType::TYPE_STRING);
            }

            $sheet->setCellValue([6, $line], $row['containers']);
            $sheet->setCellValue([7, $line], $row['valor_unitario']);
            $sheet->setCellValue([8, $line], round($row['pago'], 2));
            $sheet->setCellValue([9, $line], $row['taxa']);
        }

        $total = count($report['rows']) + 2;
        $sheet->setCellValue([1, $total], 'Total');
        $sheet->setCellValue([6, $total], $report['totais']['containers']);
        $sheet->setCellValue([8, $total], round($report['totais']['pago'], 2));

        foreach ([1, $total] as $line) {
            $sheet->getStyle([1, $line, $last, $line])->getFont()->setBold(true);
            $sheet->getStyle([1, $line, $last, $line])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
        }

        $sheet->getStyle([1, 1, $last, $total])->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BBBBBB');
        $sheet->getStyle([7, 2, 8, $total])->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle([9, 2, 9, $total])->getNumberFormat()->setFormatCode('0.0000');
        $sheet->freezePane('A2');

        foreach (range(1, $last) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'comissoes_embarque_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  list<string>  $dates */
    private function dates(array $dates): string
    {
        return implode(', ', array_map(fn (string $date) => date('d/m/Y', strtotime($date)), $dates));
    }
}
