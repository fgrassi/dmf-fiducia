<?php

namespace App\Actions\Financeiro;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * relatorioTempoRecebimento/excel (legado): a mesma tela em uma aba — só os grupos (Grupo, Operações, Média), cada grupo com
 * sua lista de reservas, ou a lista única — e o "Resumo geral" no fim.
 */
class ExportBookingReceiptTime
{
    private const HEADERS = ['Booking', 'Cliente', 'Tipo', 'Prazo IMPO', 'Boleto IMPO', 'Prazo EXPO', 'Boleto EXPO', 'Data criação', 'Data 1ª baixa', 'Dias'];

    /** @param  array<string, mixed>  $report  saída de BookingReceiptTimeReport */
    public function __invoke(array $report): StreamedResponse
    {
        return response()->streamDownload(fn () => (new Xlsx($this->build($report)))->save('php://output'), 'tempo_booking_recebimento_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array<string, mixed>  $report */
    public function build(array $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('DMF Logistics do Brasil')->setTitle('Tempo Booking x Recebimento');
        $sheet = $book->getActiveSheet()->setTitle('Tempo Recebimento');
        $last = count(self::HEADERS);
        $line = 1;

        if ($report['agrupar_por'] !== '' && ! $report['mostrar_lista']) {
            $sheet->fromArray(['Grupo', 'Operações', 'Média (dias)'], null, "A{$line}");
            $this->header($sheet, [1, $line, 3, $line]);
            $line++;

            foreach ($report['grupos'] as $group) {
                $sheet->fromArray([$group['label'], $group['count'], $group['media'] ?? '-'], null, "A{$line}");
                $line++;
            }

            $line++;
        } elseif ($report['agrupar_por'] !== '') {
            foreach ($report['grupos'] as $group) {
                $sheet->setCellValue([1, $line], "{$group['label']} — {$group['count']} ops — média ".($group['media'] ?? '-').' dias');
                $sheet->mergeCells([1, $line, $last, $line]);
                $sheet->getStyle([1, $line])->getFont()->setBold(true);
                $line++;

                $line = $this->detail($sheet, $line, $group['rows']) + 1;
            }
        } elseif ($report['mostrar_lista']) {
            $end = $this->detail($sheet, $line, $report['rows']);
            $sheet->freezePane('A'.($line + 1));
            $sheet->getStyle([1, $line, $last, $end - 1])->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BBBBBB');
            $line = $end + 1;
        }

        $medias = $report['medias'];
        $sheet->setCellValue([1, $line], 'Resumo geral');
        $sheet->getStyle([1, $line])->getFont()->setBold(true);
        $sheet->fromArray([
            ['Operações', $medias['geral']['count']],
            ['Média geral (dias)', $medias['geral']['media'] ?? '-'],
            ['Média IMPO (dias)', $medias['impo']['media'] ?? '-', 'Qtd: '.$medias['impo']['count']],
            ['Média EXPO (dias)', $medias['expo']['media'] ?? '-', 'Qtd: '.$medias['expo']['count']],
        ], null, 'A'.($line + 1));

        foreach (range(1, $last) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return $book;
    }

    /**
     * Cabeçalho + uma linha por reserva.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int a linha seguinte à última escrita
     */
    private function detail(Worksheet $sheet, int $line, array $rows): int
    {
        $sheet->fromArray(self::HEADERS, null, "A{$line}");
        $this->header($sheet, [1, $line, count(self::HEADERS), $line]);
        $line++;

        foreach ($rows as $row) {
            $sheet->fromArray([
                $row['booking'],
                $row['cliente_nome'] ?? '',
                BookingReceiptTimeReport::TYPES[$row['impor_expor']] ?? $row['impor_expor'],
                BookingReceiptTimeReport::term($row['prazo_imp']),
                BookingReceiptTimeReport::boleto($row['boleto_imp']),
                BookingReceiptTimeReport::term($row['prazo_exp']),
                BookingReceiptTimeReport::boleto($row['boleto_exp']),
                $this->date($row['data_topo']),
                $this->date($row['primeira_baixa']),
                $row['dias'] ?? '',
            ], null, "A{$line}");
            $line++;
        }

        return $line;
    }

    /** @param  array{int, int, int, int}  $range */
    private function header(Worksheet $sheet, array $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
    }

    private function date(?string $date): string
    {
        return filled($date) ? Carbon::parse($date)->format('d/m/Y') : '';
    }
}
