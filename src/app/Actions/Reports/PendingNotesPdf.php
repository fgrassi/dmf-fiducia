<?php

namespace App\Actions\Reports;

use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/** reports/geraPdf (legado): as pendências de nota embarcadas há mais de 5 dias, para imprimir. */
class PendingNotesPdf
{
    public function __construct(private PendingNotesReport $report) {}

    public function __invoke(): StreamedResponse
    {
        $pdf = $this->render($this->report->__invoke(now()->subDays(5)->toDateString()));

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'pendencias-debit-credit-notes.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function render(array $rows): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Pendências Debit/Credit Notes');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage();
        $pdf->Image(resource_path('images/logo_head_1.png'), 20, 5, 25);

        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetXY(170, 7);
        $pdf->Cell(0, 5, 'Data: '.now()->format('d/m/Y'));
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetXY(65, 5);
        $pdf->Cell(0, 5, 'Relatório de Pendências Debit/Credit Notes');
        $pdf->Ln(11);

        $body = collect($rows)->map(fn (array $row) => '<tr><td width="15%" align="center">'.e($row['booking']).'</td><td width="7.5%" align="center">'.e($row['direct_bl']).'</td>'
            .'<td width="15%" align="center">'.nl2br(e(implode("\n", $row['freight']))).'</td><td width="10%" align="center">'.e($row['shipper']).'</td>'
            .'<td width="15%">'.e($row['vessel']).'</td><td width="15%">'.e($row['origin']).'</td><td width="15%">'.e($row['destination']).'</td>'
            .'<td width="7.5%">'.Carbon::parse($row['departed'])->format('d/m/Y').'</td></tr>')->implode('');

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->writeHTML('<table border="1" cellpadding="3"><thead><tr style="font-weight:bold" align="center"><th width="15%">Booking</th><th width="7.5%">BL Direto</th><th width="15%">Frete Negociado</th><th width="10%">Shipper</th>'
            .'<th width="15%">Navio</th><th width="15%">Porto/Origem</th><th width="15%">Porto/Destino</th><th width="7.5%">Saída Efetiva</th></tr></thead>'.$body.'</table>');

        return $pdf;
    }
}
