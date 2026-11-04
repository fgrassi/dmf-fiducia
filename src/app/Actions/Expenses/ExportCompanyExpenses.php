<?php

namespace App\Actions\Expenses;

use App\Models\Despesa;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Exportar para Excel" da lista despesa (legado, DataTables): uma planilha "Despesas" com as colunas da tela, na ordem e
 * com os filtros da lista. O valor vai como número (formato 0,00); as datas, como texto dd/mm/aaaa, como no legado.
 */
class ExportCompanyExpenses
{
    private const HEADERS = [
        'Fornecedor', 'Empresa', 'Centro de Custo', 'Categoria', 'Descrição', 'Valor (R$)', 'Moeda', 'Nota Fiscal',
        'Tipo Pagamento', 'Banco', 'Data Prevista', 'Data Efetiva', 'Observação', 'Tipo Despesa', 'Status',
    ];

    private const VALUE_COLUMN = 'F';

    /** @param  Collection<int, Despesa>  $expenses */
    public function __invoke(Collection $expenses): StreamedResponse
    {
        $book = $this->build($expenses);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'despesas_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  Collection<int, Despesa>  $expenses */
    public function build(Collection $expenses): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Despesas');

        $sheet->fromArray([self::HEADERS, ...$expenses->map(fn (Despesa $expense) => [
            (string) $expense->supplier?->nome,
            Despesa::COMPANIES[$expense->empresa] ?? '',
            (string) $expense->costCenter?->nome,
            (string) $expense->category?->nome,
            (string) $expense->descricao,
            (float) $expense->valor,
            (string) $expense->currency?->symbol,
            (string) $expense->nota_fiscal,
            Despesa::PAYMENT_TYPES[$expense->tipo_pagamento] ?? '',
            (string) $expense->bank?->name,
            (string) $expense->data_prevista?->format('d/m/Y'),
            (string) $expense->data_efetiva?->format('d/m/Y'),
            (string) $expense->observacao,
            Despesa::EXPENSE_TYPES[$expense->tipo_despesa] ?? '',
            Despesa::STATUSES[$expense->status] ?? '',
        ])->all()]);

        $last = count(self::HEADERS);
        $lastLetter = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$lastLetter}1")->getFont()->setBold(true);
        $sheet->getStyle(self::VALUE_COLUMN.'2:'.self::VALUE_COLUMN.max(2, $expenses->count() + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

        foreach (range(1, $last) as $index) {
            $sheet->getColumnDimensionByColumn($index)->setAutoSize(true);
        }

        return $book;
    }
}
