<?php

namespace App\Actions\Financeiro;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * relatorioNfCapa (legado): o Excel de cada tabela da tela (o legado baixava uma por vez), aqui numa planilha só, uma aba por lista.
 */
class ExportInvoiceCoverFileReport
{
    private const CURRENCY = '[$R$-416] #,##0.00';

    /** Aba => [lista, com a coluna do arquivo, com o custo em aberto]. */
    private const SHEETS = [
        'NF capa anterior' => ['anterior', true, false],
        'NF capa posterior' => ['posterior', true, true],
        'NF sem arquivo de capa' => ['nenhum', false, false],
        'NF outro arquivo mesmo mes' => ['mesmo_mes', true, false],
    ];

    /** @param  array<string, mixed>  $report  saída de InvoiceCoverFileReport */
    public function __invoke(array $report): StreamedResponse
    {
        return response()->streamDownload(fn () => (new Xlsx($this->build($report)))->save('php://output'), 'NF_x_arquivo_de_capa_'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array<string, mixed>  $report */
    public function build(array $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('DMF Logistics do Brasil')->setTitle('NF x arquivo de capa');

        $summary = $report['resumo'];
        $sheet = $book->getActiveSheet()->setTitle('Resumo');
        $sheet->fromArray([
            ['Período', $report['periodo']],
            ['Arquivo de referência', $report['arquivo_nome']],
            ['No arquivo', $summary['no_arquivo']],
            ['Anterior', $summary['anterior']],
            ['Posterior', $summary['posterior']],
            ['Sem arquivo', $summary['nenhum']],
            ['Outro no mesmo mês', $summary['mesmo_mes']],
            ['Custo em aberto', $summary['custo_em_aberto']],
        ], null, 'A1');
        $sheet->getStyle('B8')->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        foreach (self::SHEETS as $title => [$key, $withFile, $withCost]) {
            if ($key === 'mesmo_mes' && $report[$key] === []) {
                continue;
            }

            $this->table($book->createSheet(), $title, $report[$key], $withFile, $withCost, (float) $summary['custo_em_aberto']);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function table($sheet, string $title, array $rows, bool $withFile, bool $withCost, float $totalOpen): void
    {
        $headers = ['Data da NF', 'Número da NF', 'Valor da NF', 'Reserva', ...($withFile ? ['Arquivo da capa'] : []), ...($withCost ? ['Custo em aberto'] : [])];
        $sheet->setTitle($title);
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle([1, 1, count($headers), 1])->getFont()->setBold(true);
        $sheet->fromArray(array_map(fn (array $row) => [
            $row['data_nf'], $row['numero_nf'], $row['valor_nf'], $row['booking'],
            ...($withFile ? [$row['arquivo_nome']] : []),
            ...($withCost ? [$row['custo_em_aberto']] : []),
        ], $rows), null, 'A2');

        $last = count($rows) + 1;
        $sheet->getStyle([3, 2, 3, $last + 1])->getNumberFormat()->setFormatCode(self::CURRENCY);

        if ($withCost) {
            $sheet->setCellValue([1, $last + 1], 'Total em aberto (sem repetir capa)');
            $sheet->setCellValue([6, $last + 1], $totalOpen);
            $sheet->getStyle([1, $last + 1, 6, $last + 1])->getFont()->setBold(true);
            $sheet->getStyle([6, 2, 6, $last + 1])->getNumberFormat()->setFormatCode(self::CURRENCY);
        }

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
    }
}
