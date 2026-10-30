<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/** pdfCapaContabilidade (legado): capa de uma página (continua em outra quando as linhas passam do limite), nas posições do legado. */
class CoverPdf
{
    public function __construct(private CoverSheet $sheet) {}

    public function __invoke(CapaProcesso $cover): StreamedResponse
    {
        $pdf = $this->render($cover);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), "capa-{$cover->numero}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function render(CapaProcesso $cover): TCPDF
    {
        $d = ($this->sheet)($cover);

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Capa Contabilidade');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setImageScale(4);
        $pdf->AddPage();
        $pdf->SetMargins(10, 10);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->SetFillColor(200, 195, 195);

        $this->top($pdf, $d);
        $this->tables($pdf, $d);
        $this->summary($pdf, $d);

        return $pdf;
    }

    /** @param  array<string, mixed>  $d */
    private function top(TCPDF $pdf, array $d): void
    {
        $pdf->Ln(10);
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->MultiCell(210, 10, $d['reminder'], 0, 'C', false, 0, '', '', true);
        $pdf->Ln();

        $y = $pdf->GetY();
        $this->party($pdf, $d);
        $pdf->Ln();
        $pdf->Ln();
        $y2 = $pdf->GetY();

        $pdf->SetFont('Helvetica', '', 13);
        $pdf->MultiCell(30, 12, $d['office'], 1, 'C', false, 0, 170, $y, true);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell(30, 12, $d['seller'], 0, 'C', false, 0, 170, $y + 7, true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 26);
        $pdf->MultiCell(30, 12, $d['operation'], 1, 'C', false, 0, 170, $y + 18, true);

        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->MultiCell(30, 6, 'MÊS/ANO', 1, 'L', false, 0, '', $y2, true);
        $pdf->MultiCell(90, 6, $d['month_year'], 1, 'C', false, 0, '', '', true);
        $y = $pdf->GetY();
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->MultiCell(30, 12, $d['number'], 1, 'C', false, 0, '', '', true);
        $pdf->SetFont('Helvetica', 'B', $d['operation'] === 'EXPO' ? 9 : 12);
        $pdf->MultiCell(120, 6, $d['reference'], 1, 'C', false, 0, '', $y + 6, true);
        $pdf->Ln();
        $pdf->Ln();
    }

    /** Importador/Exportador e BL; repetido no topo de cada página nova. */
    private function party(TCPDF $pdf, array $d): void
    {
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(30, 6, $d['party_label'], 1, 'L', false, 0, '', '', true);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(120, 6, $d['party'], 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(30, 6, 'BL', 1, 'L', false, 0, '', '', true);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->MultiCell(120, 6, $d['bl'], 1, 'L', false, 0, '', '', true);
    }

    /** @param  array<string, mixed>  $d */
    private function tables(TCPDF $pdf, array $d): void
    {
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(95, 6, 'Despesas efetivadas', 1, 'C', false, 0, '', '', true);
        $y = $pdf->GetY();
        $yExpenses = $this->rows($pdf, $d['expenses'], 10);

        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(95, 6, 'Receitas efetivadas no Brasil', 1, 'C', false, 0, 105, $y, true);
        $yRevenues = $this->rows($pdf, $d['revenues'], 105);

        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->MultiCell(70, 6, 'Total:', 1, 'L', false, 0, '', max($yExpenses, $yRevenues) + 5, true);
        $pdf->MultiCell(25, 6, $d['total_expenses'], 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(70, 6, 'Total:', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(25, 6, $d['total_revenues'], 1, 'L', false, 0, '', '', true);

        $this->newPageIfBelow($pdf, $d, 250);
    }

    /**
     * Uma coluna de linhas (nome e valor) a partir do x dado; devolve o y final.
     *
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function rows(TCPDF $pdf, array $rows, float $x): float
    {
        foreach ($rows as [$name, $value]) {
            $pdf->Ln();
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->MultiCell(70, 5, $name, 1, 'L', false, 0, $x, '', true);
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->MultiCell(25, 5, $value, 1, 'L', false, 0, '', '', true);
        }

        return $pdf->GetY();
    }

    /** @param  array<string, mixed>  $d */
    private function summary(TCPDF $pdf, array $d): void
    {
        $pdf->Ln();
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 11);
        $this->pair($pdf, 'Diferença no Brasil (Receitas - Despesas)', $d['difference']);
        $pdf->Ln();
        $pdf->Ln();
        $this->newPageIfBelow($pdf, $d, 250);

        $this->title($pdf, $d['abroad_label'], 'C');
        $this->pair($pdf, 'Diferença de frete marítimo', $d['abroad']);
        $pdf->Ln();
        $pdf->Ln();
        $this->newPageIfBelow($pdf, $d, 250);

        $this->title($pdf, 'Comissões a serem recebidas no Brasil', 'C');
        $this->pair($pdf, 'Comissão a ser paga pelo armador', $d['commission']);
        $pdf->Ln();
        $pdf->Ln();
        $this->newPageIfBelow($pdf, $d, 240);

        $this->pair($pdf, 'Custo Financeiro', $d['financial_cost']);
        $pdf->Ln();
        $pdf->Ln();
        $this->newPageIfBelow($pdf, $d, 240);

        $pdf->SetFont('Helvetica', 'B', 12);
        $this->title($pdf, 'Lucratividade no processo', 'C');

        foreach ([['EM USD/EUR', $d['foreign_total']], ['Taxa Conversão', $d['conversion']], ['EM R$', $d['difference']], ['Custo Financeiro', $d['financial_cost']], ['Resultado Operação', $d['result']]] as [$label, $value]) {
            $this->pair($pdf, $label, $value);
            $pdf->Ln();
        }
    }

    private function title(TCPDF $pdf, string $text, string $align): void
    {
        $pdf->MultiCell(130, 6, $text, 1, $align, false, 0, '', '', true);
        $pdf->Ln();
    }

    private function pair(TCPDF $pdf, string $label, string $value): void
    {
        $pdf->MultiCell(95, 6, $label, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(35, 6, $value, 1, 'L', false, 0, '', '', true);
    }

    /** @param  array<string, mixed>  $d */
    private function newPageIfBelow(TCPDF $pdf, array $d, int $limit): void
    {
        if ($pdf->GetY() > $limit) {
            $pdf->AddPage();
            $this->party($pdf, $d);
            $pdf->Ln();
            $pdf->Ln();
        }
    }
}
