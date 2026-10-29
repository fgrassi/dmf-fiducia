<?php

namespace App\Actions\Booking\Documents;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * valoresCompra, valoresDespesa e valoresDespesasDocumentacao (legado): PDF A4 da relação de despesas da reserva.
 * Cabeçalho, tabelas por BL e total vêm de ExpenseStatement; a altura da linha acompanha o texto da despesa
 * (o legado cortava textos longos nas versões de compra e documentação).
 */
class ExpenseStatementPdf
{
    private const FILL = [93, 89, 80];

    public function __construct(private ExpenseStatement $statement) {}

    public function __invoke(Embarque $booking, string $kind): StreamedResponse
    {
        $pdf = $this->render($booking, $kind);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), "{$booking->booking}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render(Embarque $booking, string $kind): TCPDF
    {
        $statement = ($this->statement)($booking, $kind);
        $title = ['purchase' => 'Valores de Compra', 'sale' => 'Valores de Despesa', 'documentation' => 'Valores de Despesa'][$kind];

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle("DMF Logistics - {$title}");
        $pdf->SetSubject('Valores de Despesa');
        $pdf->SetKeywords('Valores de Despesa');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setImageScale(4);
        $pdf->AddPage();
        $pdf->setMargins(10, 7);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->Ln(25);
        $pdf->Image(resource_path('images/logo.png'), 15, 12, 30);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->MultiCell(100, 0, 'DMF LOGISTICS DO BRASIL LTDA', 0, 'L', false, 0, 60, 10, true);
        $pdf->Ln();
        $pdf->Ln(10);
        $pdf->SetFont('Helvetica', 'BI', 10);
        $pdf->Cell(0, 0, "Reserva: {$booking->booking}", 0, 2, 'L', false);
        $pdf->Ln(5);
        $pdf->Ln();
        $pdf->Cell(180, 4, 'Consignee: '.(string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_importador)->value('nome'), 'LTRB', 0, 'L', false);
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'BU', 12);
        $pdf->Cell(180, 4, 'Relação de Despesas ', '', 0, 'L', false);
        $pdf->Ln(10);

        $this->headerRow($pdf, $statement['columns']);

        foreach ($statement['groups'] as $group) {
            if ($group['title'] !== null) {
                $this->bar($pdf, $group['title']);
            }

            foreach ($group['rows'] as $row) {
                $this->row($pdf, $statement['columns'], $row);
            }
        }

        if ($statement['message'] !== null) {
            $pdf->Cell(80, 4, $statement['message'], 'LTRB', 0, 'L', false);
        }

        if ($statement['total'] !== null) {
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFillColor(...self::FILL);
            $pdf->MultiCell(60, 4, 'TOTAL', 'LTB', 'L', true, 0, '', '', true);
            $pdf->MultiCell(120, 4, $statement['total'], 'TRB', 'R', true, 0, '', '', true);
        }

        if ($statement['footnote'] !== null) {
            $pdf->Ln();
            $pdf->SetTextColor(0);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->MultiCell(0, 0, $statement['footnote'], 0, 'L', false, 0, '', '', true);
        }

        return $pdf;
    }

    /** @param  list<array{0: string, 1: int, 2: string}>  $columns */
    private function headerRow(TCPDF $pdf, array $columns): void
    {
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFillColor(...self::FILL);
        $pdf->SetFont('Helvetica', 'B', 8);

        foreach ($columns as [$label, $width]) {
            $pdf->Cell($width, 4, $label.' ', 'LTRB', 0, 'L', true);
        }

        $pdf->Ln();
    }

    private function bar(TCPDF $pdf, string $title): void
    {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFillColor(...self::FILL);
        $pdf->Cell(180, 4, $title, 'LTRB', 0, 'L', true);
        $pdf->Ln();
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}>  $columns
     * @param  list<string>  $row
     */
    private function row(TCPDF $pdf, array $columns, array $row): void
    {
        $pdf->SetTextColor(0);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 8);
        $height = max(4, $pdf->getStringHeight($columns[0][1], $row[0]));

        foreach ($columns as $i => [, $width, $align]) {
            $pdf->MultiCell($width, $height, $row[$i], 'LTRB', $align, false, 0, '', '', true, 0, false, true, $height, 'M');
        }

        $pdf->Ln();
    }
}
