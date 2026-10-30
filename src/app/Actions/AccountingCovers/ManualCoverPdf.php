<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcessoManual;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/** pdfCapaContabilidadeManual (legado): capa de uma página da despesa lançada à mão, nas posições do legado. */
class ManualCoverPdf
{
    public function __construct(private ManualCoverSheet $sheet) {}

    public function __invoke(CapaProcessoManual $cover): StreamedResponse
    {
        $pdf = $this->render($cover);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), "capa-manual-{$cover->numero}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function render(CapaProcessoManual $cover): TCPDF
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
        $pdf->Ln(10);
        $pdf->Ln();
        $pdf->Ln();

        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->MultiCell(0, 5, $d['expense'], 1, 'C', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->MultiCell(0, 5, $d['client'], 1, 'C', false, 0, '', '', true);
        $pdf->Ln(10);

        $pdf->MultiCell(35, 6, 'MÊS/ANO', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(120, 6, $d['month_year'], 1, 'C', false, 0, '', '', true);
        $pdf->MultiCell(35, 6, $d['number'], 1, 'C', false, 0, '', '', true);
        $pdf->Ln(10);

        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(95, 6, 'Despesas Efetivadas', 1, 'C', false, 0, '', '', true);
        $pdf->MultiCell(95, 6, 'Receitas Efetivadas no Brasil', 1, 'C', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', 10);
        $this->pair($pdf, $d['expense'], $d['cost'], $d['expense'], $d['revenue']);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 10);
        $this->pair($pdf, 'Total:', $d['cost'], 'Total:', $d['revenue']);
        $pdf->Ln();
        $pdf->Ln();

        $pdf->MultiCell(95, 6, 'Diferença no Brasil (Receitas - Despesas)', 1, 'C', false, 0, '', '', true);
        $pdf->MultiCell(35, 6, $d['difference'], 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->Ln();

        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->MultiCell(130, 6, 'Lucratividade no processo', 1, 'C', false, 0, '', '', true);
        $pdf->Ln();

        foreach ([['EM USD/EUR', $d['foreign_profit']], ['Taxa Conversão', $d['conversion']], ['EM R$', $d['difference']]] as $i => [$label, $value]) {
            $i && $pdf->Ln();
            $pdf->MultiCell(95, 6, $label, 1, 'L', false, 0, '', '', true);
            $pdf->MultiCell(35, 6, $value, 1, 'L', false, 0, '', '', true);
        }

        return $pdf;
    }

    /** Despesa (esquerda) e receita (direita): nome e valor de cada lado. */
    private function pair(TCPDF $pdf, string $leftName, string $leftValue, string $rightName, string $rightValue): void
    {
        $pdf->MultiCell(70, 5, $leftName, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(25, 5, $leftValue, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(70, 5, $rightName, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(25, 5, $rightValue, 1, 'L', false, 0, '', '', true);
    }
}
