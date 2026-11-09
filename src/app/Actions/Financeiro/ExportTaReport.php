<?php

namespace App\Actions\Financeiro;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** relatorioTa/excel (legado): as mesmas 15 colunas da tela, linhas de saída efetiva em verde e a linha Total no fim. */
class ExportTaReport
{
    private const HEADERS = ['Shipper', 'Booking', 'Nr. Fatura', 'Origem', 'Destino', 'ETD', 'Saída Efetiva', 'Quantidade de container', 'Total TA', 'Recebido (Cliente)', 'Pago', 'Taxa de Pagamento', 'Valor pago', 'Saldo', 'Valor TA R$'];

    /** @param  Collection<int, array<string, mixed>>  $rows  saída de TaReport */
    public function __invoke(Collection $rows): StreamedResponse
    {
        return response()->streamDownload(fn () => (new Xlsx($this->build($rows)))->save('php://output'), 'relatorio_ta_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    public function build(Collection $rows): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('DMF Logistics do Brasil')->setTitle('Relatório TA');
        $sheet = $book->getActiveSheet()->setTitle('Relatório TA');
        $sheet->fromArray(self::HEADERS, null, 'A1');

        $line = 2;

        foreach ($rows as $row) {
            $sheet->fromArray([
                $row['shipper'], $row['booking'], $row['fatura'], $row['origem'], $row['destino'],
                $this->date($row['etd']), $this->date($row['saida_efetiva']), $row['qtd_container'], $row['total_ta'],
                $row['recebido'], $row['embarque_pago'], $row['taxa_pagamento'], $row['valor_pago'], $row['saldo'], $row['valor_ta_rs'],
            ], null, "A{$line}");

            if (filled($row['saida_efetiva'])) {
                $sheet->getStyle("A{$line}:O{$line}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('B4E1B5');
            }

            $line++;
        }

        if ($rows->isNotEmpty()) {
            $sheet->setCellValue("A{$line}", 'Total');
            $sheet->setCellValue("H{$line}", $rows->sum('qtd_container'));
            $sheet->setCellValue("I{$line}", $rows->sum('total_ta'));
            $sheet->setCellValue("M{$line}", $rows->sum('valor_pago'));
            $sheet->setCellValue("N{$line}", $rows->sum('saldo'));
            $sheet->setCellValue("O{$line}", $rows->sum('valor_ta_rs'));
        }

        foreach (range(1, count(self::HEADERS)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return $book;
    }

    private function date(?string $date): string
    {
        return filled($date) ? Carbon::parse($date)->format('d/m/Y') : '';
    }
}
