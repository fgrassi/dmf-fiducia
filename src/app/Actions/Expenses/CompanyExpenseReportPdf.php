<?php

namespace App\Actions\Expenses;

use App\Models\Despesa;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * despesa/relatorio (legado): PDF A4 paisagem das despesas filtradas, só com as colunas marcadas. Cada coluna tem um peso
 * (largura relativa); o espaço das colunas desmarcadas é dividido por igual entre as marcadas. No fim, valor total
 * (somado sem olhar a moeda, como o legado, que imprime tudo com "R$") e quantidade de itens.
 */
class CompanyExpenseReportPdf
{
    /** chave => [título, peso da largura] */
    public const COLUMNS = [
        'supplier' => ['Fornecedor', 0.10],
        'company' => ['Empresa', 0.055],
        'cost_center' => ['Centro de Custo', 0.09],
        'category' => ['Categoria', 0.08],
        'description' => ['Descrição', 0.105],
        'value' => ['Valor', 0.07],
        'currency' => ['Moeda', 0.04],
        'invoice' => ['Nota Fiscal', 0.065],
        'payment_type' => ['Tipo Pagamento', 0.075],
        'bank' => ['Banco', 0.08],
        'due_date' => ['Data Prevista', 0.06],
        'paid_date' => ['Data Efetiva', 0.06],
        'expense_type' => ['Tipo Despesa', 0.07],
        'status' => ['Status', 0.05],
    ];

    private const WIDTH = 285;

    private const LINE = 3.2;

    private const PAGE_BOTTOM = 195;

    /**
     * @param  Collection<int, Despesa>  $expenses
     * @param  list<string>  $columns  chaves de COLUMNS
     * @param  list<string>  $filters  descrição dos filtros ativos
     */
    public function __invoke(Collection $expenses, array $columns, array $filters, string $createdBy): StreamedResponse
    {
        $pdf = $this->render($expenses, $columns, $filters, $createdBy);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'relatorio-despesas.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @param  Collection<int, Despesa>  $expenses
     * @param  list<string>  $columns
     * @param  list<string>  $filters
     */
    public function render(Collection $expenses, array $columns, array $filters, string $createdBy): TCPDF
    {
        $columns = array_values(array_intersect(array_keys(self::COLUMNS), $columns));
        $widths = $this->widths($columns);

        $pdf = new TCPDF('L', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Relatório de Despesas');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setMargins(5, 5);
        $pdf->SetAutoPageBreak(false, 7);
        $pdf->AddPage();

        $pdf->Image(resource_path('images/logo_head_1.png'), 10, 10, 30);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->MultiCell(0, 0, 'Relatório gerado em: '.now()->format('d/m/Y H:i:s'), 0, 'L', false, 1, 240, 5);
        $pdf->MultiCell(0, 0, 'Relatório criado por: '.$createdBy, 0, 'L', false, 1, 240, null);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->MultiCell(0, 0, 'DMF Logistics - Relatório de Despesas', 0, 'L', false, 1, 110, 15);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetY(30);
        $pdf->MultiCell(0, 4, 'Filtros Ativos: '.implode(' | ', $filters), 0, 'L', false, 1, 5);
        $pdf->Ln(1);

        $this->header($pdf, $columns, $widths);

        $total = 0.0;

        foreach ($expenses as $expense) {
            $pdf->SetFont('Helvetica', '', 7);
            $cells = $this->cells($expense, $columns);
            $lines = max(array_map(fn (string $text, int $i) => $pdf->getNumLines($text, $widths[$i]), $cells, array_keys($cells)) ?: [1]);
            $height = $lines * self::LINE + 1;

            if ($pdf->GetY() + $height > self::PAGE_BOTTOM) {
                $pdf->AddPage();
                $this->header($pdf, $columns, $widths);
            }

            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetTextColor(0);
            $this->row($pdf, $cells, $widths, $height);

            $total += (float) $expense->valor;
        }

        $this->totals($pdf, $total, $expenses->count());

        return $pdf;
    }

    /**
     * @param  list<string>  $columns
     * @return list<float>
     */
    private function widths(array $columns): array
    {
        if ($columns === []) {
            return [];
        }

        $used = array_sum(array_map(fn (string $key) => self::COLUMNS[$key][1], $columns));
        $extra = self::WIDTH * (1 - $used) / count($columns);

        return array_map(fn (string $key) => self::WIDTH * self::COLUMNS[$key][1] + $extra, $columns);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<float>  $widths
     */
    private function header(TCPDF $pdf, array $columns, array $widths): void
    {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(93, 89, 80);
        $pdf->SetTextColor(255);

        $this->row($pdf, array_map(fn (string $key) => self::COLUMNS[$key][0], $columns), $widths, 8, 'C', true, 'M');
    }

    /**
     * Uma linha de células lado a lado. O x é passado a cada célula: com `y` informado o TCPDF volta ao x da margem.
     *
     * @param  list<string>  $cells
     * @param  list<float>  $widths
     */
    private function row(TCPDF $pdf, array $cells, array $widths, float $height, string $align = 'L', bool $fill = false, string $valign = 'T'): void
    {
        $x = $pdf->getMargins()['left'];
        $y = $pdf->GetY();

        foreach ($cells as $i => $text) {
            $pdf->MultiCell($widths[$i], $height, $text, 1, $align, $fill, 0, $x, $y, true, 0, false, true, $height, $valign);
            $x += $widths[$i];
        }

        $pdf->SetXY($pdf->getMargins()['left'], $y + $height);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function cells(Despesa $expense, array $columns): array
    {
        $values = [
            'supplier' => (string) $expense->supplier?->nome,
            'company' => Despesa::COMPANIES[$expense->empresa] ?? '',
            'cost_center' => (string) $expense->costCenter?->nome,
            'category' => (string) $expense->category?->nome,
            'description' => (string) $expense->descricao,
            'value' => 'R$ '.number_format((float) $expense->valor, 2, ',', '.'),
            'currency' => (string) $expense->currency?->symbol,
            'invoice' => (string) $expense->nota_fiscal,
            'payment_type' => Despesa::PAYMENT_TYPES[$expense->tipo_pagamento] ?? '',
            'bank' => (string) $expense->bank?->name,
            'due_date' => (string) $expense->data_prevista?->format('d/m/Y'),
            'paid_date' => (string) $expense->data_efetiva?->format('d/m/Y'),
            'expense_type' => Despesa::EXPENSE_TYPES[$expense->tipo_despesa] ?? '',
            'status' => Despesa::STATUSES[$expense->status] ?? '',
        ];

        return array_map(fn (string $key) => $values[$key], $columns);
    }

    private function totals(TCPDF $pdf, float $total, int $count): void
    {
        if ($pdf->GetY() + 25 > self::PAGE_BOTTOM) {
            $pdf->AddPage();
        }

        $pdf->Ln(10);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(93, 89, 80);
        $pdf->SetTextColor(255);
        $pdf->Cell(100, 6, 'Valor Total:', 1, 0, 'C', true);
        $pdf->Cell(100, 6, 'Quantidade de Itens:', 1, 1, 'C', true);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(0);
        $pdf->Cell(100, 6, 'R$'.number_format($total, 2, ',', '.'), 1, 0, 'C');
        $pdf->Cell(100, 6, (string) $count, 1, 1, 'C');
    }
}
