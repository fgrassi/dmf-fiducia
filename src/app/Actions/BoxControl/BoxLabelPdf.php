<?php

namespace App\Actions\BoxControl;

use App\Models\ControleCaixa;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/** geraPdf (legado): etiqueta da caixa (nome e número) e a relação de reservas dentro dela. */
class BoxLabelPdf
{
    private const FONT_SIZE = 6;

    private const LINE = 4;

    public function __invoke(ControleCaixa $box): StreamedResponse
    {
        $pdf = $this->render($box);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), "caixa-{$box->getKey()}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function render(ControleCaixa $box): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - PDF Caixa');
        $pdf->SetHeaderMargin(15);
        $pdf->SetFooterMargin(23);
        $pdf->setImageScale(4);
        $pdf->setPrintHeader(false);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->AddPage();
        $pdf->SetMargins(6, 6);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->Ln(20);

        $this->label($pdf, $box);
        $this->bookings($pdf, $box);

        return $pdf;
    }

    private function label(TCPDF $pdf, ControleCaixa $box): void
    {
        $pdf->MultiCell(110, 30, '', 1, 'L', false, 0, '', 6, true);
        $pdf->MultiCell(110, 22, '', 1, 'L', false, 0, '', 10, true);
        $pdf->SetFont('Helvetica', '', 13);
        $pdf->MultiCell(80, 8, 'DMF LOGISTICS DO BRASIL', 0, 'C', false, 0, 20, '', true);
        $pdf->Ln(9);
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->MultiCell(80, 8, (string) $box->nome_caixa, 0, 'C', false, 0, 10, '', true);
        $pdf->MultiCell(20, 8, (string) $box->getKey(), 0, 'C', false, 0, 90, '', true);
        $pdf->Ln(17);
    }

    private function bookings(TCPDF $pdf, ControleCaixa $box): void
    {
        $pdf->SetFont('Helvetica', 'B', self::FONT_SIZE);
        $pdf->MultiCell(25, self::LINE, 'Booking', 1, 'C', false, 0, '', '', true);
        $pdf->MultiCell(25, self::LINE, 'BL', 1, 'C', false, 0, '', '', true);
        $pdf->MultiCell(60, self::LINE, 'Cliente', 1, 'C', false, 0, '', '', true);
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', self::FONT_SIZE);

        foreach ($box->bookings()->with('booking.cliente')->orderBy('controle_caixa_embarque_id')->get() as $item) {
            $cells = [
                [25, (string) $item->booking?->booking],
                [25, (string) $item->booking?->house_bl],
                [60, (string) $item->booking?->cliente?->nome],
            ];
            $lines = max(1, ...array_map(fn (array $cell) => $pdf->getNumLines($cell[1], $cell[0]), $cells));

            foreach ($cells as [$width, $text]) {
                $pdf->MultiCell($width, self::LINE * $lines, $text, 1, 'C', false, 0, '', '', true);
            }

            $pdf->Ln();
        }
    }
}
