<?php

namespace App\Actions\Reports;

use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/** relCustosCorreio/geraPdf (legado): as duas listas do relatório de custos de correio, cada uma com o seu total, e o total geral. */
class MailCostsPdf
{
    public function __construct(private MailCostsReport $report) {}

    /** @param  array{data_ini?: ?string, data_fim?: ?string}  $input */
    public function __invoke(array $input): StreamedResponse
    {
        $pdf = $this->render($this->report->__invoke($input), $input['data_ini'] ?? today()->toDateString(), $input['data_fim'] ?? today()->toDateString());

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'custos-correio.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** @param  array{rows: list<array<string, mixed>>, linked_total: float, form_total: float, total: float}  $report */
    public function render(array $report, string $from, string $to): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Relatório Custos Correio');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage();
        $pdf->Image(resource_path('images/logo_head_1.png'), 25, 5, 25);

        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetXY(170, 5);
        $pdf->Cell(0, 5, 'Data: '.now()->format('d/m/Y'));
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetXY(55, 5);
        $pdf->Cell(0, 5, 'RELATÓRIO CUSTOS DE CORREIO');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(55, 11);
        $pdf->Cell(60, 5, Carbon::parse($from)->format('d/m/Y').' a '.Carbon::parse($to)->format('d/m/Y'), 0, 0, 'C');
        $pdf->Ln(10);

        $linked = collect($report['rows'])->where('is_linked', true);
        $form = collect($report['rows'])->where('is_form', true);

        $pdf->SetFont('Helvetica', '', 8);

        if ($linked->isNotEmpty()) {
            $body = $linked->map(fn (array $row) => '<tr><td width="30%" align="center">'.e($row['num_sedex']).'</td>'
                .'<td width="30%">'.implode('<br>', array_map(fn (array $booking) => e($booking['name']), $row['bookings'])).'</td>'
                .'<td width="20%">'.implode('<br>', array_fill(0, count($row['bookings']), $this->money($row['value_per_booking']))).'</td>'
                .'<td width="20%">'.$this->money($row['valor']).'</td></tr>')->implode('');

            $pdf->writeHTML('<table border="1" cellpadding="2"><thead><tr style="font-weight:bold" align="center"><th width="30%">Nº Correio</th><th width="30%">Reserva</th><th width="20%">Valor/Reserva</th><th width="20%">Valor Total</th></tr></thead>'.$body
                .'<tr style="font-weight:bold"><td width="80%">Total Processos</td><td width="20%">'.$this->money($report['linked_total']).'</td></tr></table><br>');
        }

        if ($form->isNotEmpty()) {
            $body = $form->map(fn (array $row) => '<tr><td width="30%" align="center">'.e($row['num_sedex']).'</td><td width="50%" align="center">'.e($row['destinatario']).'</td>'
                .'<td width="20%">'.$this->money($row['valor']).'</td></tr>')->implode('');

            $pdf->writeHTML('<table border="1" cellpadding="2"><thead><tr style="font-weight:bold" align="center"><th width="30%">Nº Correio</th><th width="50%">Descrição</th><th width="20%">Valor Total</th></tr></thead>'.$body
                .'<tr style="font-weight:bold"><td width="80%">Total Processos</td><td width="20%">'.$this->money($report['form_total']).'</td></tr></table><br>');
        }

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->writeHTML('<table border="1" cellpadding="2"><tr><td width="80%">Total Correios</td><td width="20%">'.$this->money($report['total']).'</td></tr></table>');

        return $pdf;
    }

    private function money(?float $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }
}
