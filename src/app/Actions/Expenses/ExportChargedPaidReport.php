<?php

namespace App\Actions\Expenses;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * relatorioDespesasCobradasPagas/excel (legado): uma linha por despesa e reserva, na ordem da tela, e a linha "Total".
 * Datas como texto dd/mm/aaaa; valores como número.
 */
class ExportChargedPaidReport
{
    private const HEADERS = ['Despesa', 'Tipo', 'Booking', 'Cliente', 'ETD', 'ETA', 'Data da cobrança', 'Data do pagamento', 'Cobrado', 'Pago', 'Resultado'];

    /** @param  array{grupos: list<array<string, mixed>>, totais: array<string, mixed>}  $report */
    public function __invoke(array $report): StreamedResponse
    {
        $book = $this->build($report);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'despesas_cobradas_pagas_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array{grupos: list<array<string, mixed>>, totais: array<string, mixed>}  $report */
    public function build(array $report): Spreadsheet
    {
        $date = fn (string $value) => $value !== '' && ($time = strtotime($value)) ? Carbon::createFromTimestamp($time)->format('d/m/Y') : '';

        $rows = collect($report['grupos'])->flatMap(fn (array $group) => $group['rows'])->map(fn (array $row) => [
            $row['despesa_nome'], $row['despesa_tipo'], $row['booking'], $row['cliente_nome'],
            $date($row['etd']), $date($row['eta']), $date($row['data_cobranca']), $date($row['data_pagamento']),
            $row['cobrado'], $row['pago'], $row['resultado'],
        ]);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Cobradas x pagas');
        $sheet->fromArray([self::HEADERS, ...$rows->all()]);

        if ($rows->isNotEmpty()) {
            $total = $rows->count() + 2;
            $sheet->setCellValue("A{$total}", 'Total');
            $sheet->setCellValue("I{$total}", $report['totais']['cobrado']);
            $sheet->setCellValue("J{$total}", $report['totais']['pago']);
            $sheet->setCellValue("K{$total}", $report['totais']['resultado']);
            $sheet->getStyle("I2:K{$total}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            $sheet->getStyle("A{$total}:K{$total}")->getFont()->setBold(true);
        }

        $sheet->getStyle('A1:K1')->getFont()->setBold(true);

        foreach (range(1, count(self::HEADERS)) as $index) {
            $sheet->getColumnDimensionByColumn($index)->setAutoSize(true);
        }

        return $book;
    }
}
