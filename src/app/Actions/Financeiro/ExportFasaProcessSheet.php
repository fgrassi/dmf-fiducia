<?php

namespace App\Actions\Financeiro;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Botão "Exportar XLSX" da Planilha Processos FASA (legado): uma aba por seção, com a linha de total e os vencidos em rosa. */
class ExportFasaProcessSheet
{
    private const HEADERS = ['Empresa', 'Shipper', 'Consignee', 'Booking', 'Valor', 'Fatura', 'Vencimento', 'Situação', 'Num. Cobrança', 'Data do pagamento', 'Atraso', 'Obs'];

    private const KEYS = ['empresa', 'shipper', 'consignee', 'booking', 'valor', 'fatura', 'vencimento', 'situacao', 'num_cobranca', 'data_pagamento', 'atraso', 'obs'];

    private const MONEY = '_-[$R$-416]* #,##0.00_-;-[$R$-416]* #,##0.00_-;_-[$R$-416]* "-"??_-;_-@_-';

    /** @param  array<string, array<string, mixed>>  $sections  saída de FasaProcessSheet */
    public function __invoke(array $sections): StreamedResponse
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('DMF Logistics do Brasil')->setTitle('Planilha Processos FASA');
        $book->removeSheetByIndex(0);
        $last = count(self::HEADERS);

        foreach ($sections as $section) {
            $sheet = $book->createSheet()->setTitle(mb_substr($section['title'], 0, 31));
            $sheet->fromArray(self::HEADERS, null, 'A1');

            foreach ($section['rows'] as $y => $row) {
                foreach (self::KEYS as $x => $key) {
                    $sheet->setCellValueExplicit([$x + 1, $y + 2], $row[$key], is_float($row[$key]) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }

                if ($row['situacao'] === 'vencido') {
                    $this->fill($sheet, [1, $y + 2, $last, $y + 2], 'FFF3F3');
                }
            }

            $total = count($section['rows']) + 2;
            $sheet->setCellValue([1, $total], $section['total_label']);
            $sheet->setCellValue([5, $total], round($section['total'], 2));
            $this->fill($sheet, [1, $total, $last, $total], 'E8E8E8');
            $sheet->getStyle([1, $total, $last, $total])->getFont()->setBold(true);

            $this->fill($sheet, [1, 1, $last, 1], 'E8E8E8');
            $sheet->getStyle([1, 1, $last, 1])->getFont()->setBold(true);
            $sheet->getStyle([1, 1, $last, $total])->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BBBBBB');
            $sheet->getStyle([5, 2, 5, $total])->getNumberFormat()->setFormatCode(self::MONEY);
            $sheet->getStyle([2, 2, 3, $total])->getAlignment()->setWrapText(true);
            $sheet->freezePane('A2');

            foreach (range(1, $last) as $column) {
                $dimension = $sheet->getColumnDimensionByColumn($column);
                // Nomes longos de empresa, shipper e consignee não esticam a coluna.
                $column <= 3 ? $dimension->setWidth([1 => 18, 2 => 22, 3 => 22][$column]) : $dimension->setAutoSize(true);
            }
        }

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'planilha_fasa_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array{int, int, int, int}  $range */
    private function fill($sheet, array $range, string $rgb): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }
}
