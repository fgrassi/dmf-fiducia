<?php

namespace App\Actions\AccountingCovers;

use App\Enums\OperationType;
use App\Models\Embarque;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Botão "Excel" da lista do relatório de resultado (legado): as linhas da tela, com os subtotais e os totais. */
class ExportCoverResultReport
{
    private const HEADERS = ['#', 'Capa', 'Nº', 'Reserva', 'HBL', 'Cliente', 'Tipo', 'Impo/Expo', 'Mercadoria', 'País Origem', 'Origem', 'País Destino', 'Destino', 'Data Pagamento', 'Armador', 'Cia Aérea', 'Agente', 'Vendedor', 'Centro Custo', 'Responsável Fechamento', 'Cotação', 'USD', 'Tx. Conv.', 'R$', 'EU', 'Tx. Conv.', 'Custos Financeiros', 'DEM/DET/COU/SEG'];

    /** Colunas de valor (começam em USD); o resto sai como texto, para o Excel não comer zeros de HBL e reserva. */
    private const FIRST_AMOUNT = 22;

    private const LAST_AMOUNT = 27;

    /** @param  array{lines: list<array<string, mixed>>, total: array<string, float>}  $report */
    public function __invoke(array $report): StreamedResponse
    {
        $count = 0;
        $data = [self::HEADERS];

        foreach ($report['lines'] as $line) {
            $data[] = isset($line['subtotal']) ? $this->summary($line['subtotal'], $line['sum']) : $this->row($line['row'], ++$count);
        }

        if ($count) {
            $total = $report['total'];
            $data[] = $this->summary('TOTAL', $total);
            $data[] = $this->summary('TOTAL (R$ + conversões)', ['usd' => $total['tx_usd'] + $total['rs'] + $total['tx_euro']]);
        }

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Resultado');

        foreach ($data as $y => $cells) {
            foreach ($cells as $x => $value) {
                if ($value !== null && $value !== '') {
                    $sheet->setCellValueExplicit([$x + 1, $y + 1], $value, is_string($value) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
                }
            }
        }

        $sheet->getStyle([1, 1, count(self::HEADERS), 1])->getFont()->setBold(true);
        $sheet->getStyle([self::FIRST_AMOUNT, 2, self::LAST_AMOUNT, count($data)])->getNumberFormat()->setFormatCode('#,##0.00');

        foreach (range(1, count(self::HEADERS)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'resultado_capas_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @return list<string|float|null> */
    private function row(stdClass $row, int $number): array
    {
        $isCover = (bool) $row->capa_processo_id;

        return [
            (string) $number,
            $row->numero_capa,
            $isCover ? (string) $row->cover_number : '',
            $row->booking,
            $row->house_bl,
            $row->client,
            $isCover ? mb_strtoupper(Embarque::TYPES[$row->tipo_embarque] ?? '') : 'Capa Manual',
            $isCover ? OperationType::labelFor($row->operation) : '',
            $row->mercadoria,
            $row->pais_origem,
            $row->porto_origem,
            $row->pais_destino,
            $row->porto_destino,
            $row->data_pagamento ? Carbon::parse($row->data_pagamento)->format('d/m/Y') : '',
            $row->armador,
            $row->cia_aerea,
            $row->agent,
            $row->vendedor,
            $row->office,
            $row->closer,
            $row->quotation,
            $row->usd,
            $row->tx_usd,
            $row->rs,
            $row->euro,
            $row->tx_euro,
            $row->financial_cost,
            $row->category,
        ];
    }

    /**
     * @param  array<string, float>  $sum  chaves ausentes saem em branco
     * @return list<string|float|null>
     */
    private function summary(string $label, array $sum): array
    {
        return [$label, ...array_fill(0, self::FIRST_AMOUNT - 2, null), ...array_map(fn (string $key) => $sum[$key] ?? null, ['usd', 'tx_usd', 'rs', 'euro', 'tx_euro', 'financial_cost']), null];
    }
}
