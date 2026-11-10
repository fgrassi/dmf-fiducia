<?php

namespace App\Actions\Courier;

use App\Models\ControleCourier;
use App\Models\Pessoa;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * geraPdf (legado): a "COURRIER NOTE" para o agente, em inglês, com as reservas do courier, o valor total e a metade devida
 * à DMF, mais os dados bancários da DMF. Mesmas posições e textos do legado. ponytail: título em Helvetica (o legado usa
 * BebasNeue).
 *
 * Igual ao legado, a linha "DUE TO" fica sem o nome do agente, e a data é a de hoje.
 */
class CourierPdf
{
    private const RED = [165, 29, 33];

    private const FONT_SIZE = 9;

    private const LINE = 5;

    private const BANK_DETAILS = [
        'Beneficiary’s Name: DMF LOGISTICS DO BRASIL LTDA ',
        'Beneficiary’s Address: RUA BORGES DE MEDEIROS 274 2º ANDAR ',
        'Intermediary Bank: BANK OF AMERICA, N.A. - New York ',
        'Swift: BOFAUS3N ',
        'ACCOUNT NR: 6550921296 ',
        'BANK: BANCO BRADESCO SA - Porto Alegre ',
        'ADDRESS: Pça. Osvaldo Cruz 10 4º andar - Centro - Porto Alegre - RS - Brasil ',
        'SWIFT: BBDEBRSPSPO ',
        'AGENCY: 1587 ',
        'ACCOUNT: 200526-3 ',
        'IBAN NUMBER: BR83 6074 6948 0732 7000 0005 266c 1 ',
    ];

    public function __invoke(ControleCourier $courier): StreamedResponse
    {
        $pdf = $this->render($courier);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), "courier-{$courier->getKey()}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function render(ControleCourier $courier): TCPDF
    {
        $bookings = $courier->bookings()->with('booking')->orderBy('ctr_courier_embarque_id')->get()->pluck('booking')->filter();
        $agentId = $bookings->first()?->pessoa_id_representante;
        $agent = $agentId ? (string) Pessoa::query()->whereKey($agentId)->value('nome') : '';
        $total = (float) $courier->valor;
        $half = $total / 2;

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Courrier');
        $pdf->SetSubject('Documento de Courrier ');
        $pdf->SetKeywords('DMF Documento de Courrier');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setImageScale(4);
        $pdf->AddPage();
        $pdf->SetMargins(20, 10);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->SetFillColor(209, 211, 212);
        $pdf->SetDrawColor(...self::RED);

        $pdf->Image(resource_path('images/logo_head_1.png'), 40, 15, 30);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(...self::RED);
        $pdf->MultiCell(0, self::LINE, 'COURRIER NOTE ', 0, 'L', false, 1, 90, 20);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(190, self::LINE, 'Date '.now()->format('d/m/Y'), 0, 'R', false, 1, 10, 10);

        $pdf->SetFont('Helvetica', '', self::FONT_SIZE);
        $pdf->MultiCell(190, 10, 'INVOICE', 'BT', 'L', false, 0, 10, 35, true);
        $pdf->Ln(15);
        $pdf->MultiCell(190, 10, 'TO', 'BT', 'L', false, 0, 10, '', true);
        $pdf->Ln(17);
        $pdf->MultiCell(95, self::LINE, 'BOOKING', 'BTR', 'C', false, 0, 10, '', true);
        $pdf->MultiCell(95, self::LINE, 'HBL', 'LBT', 'C', false, 0, 105, '', true);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);

        foreach ($bookings as $booking) {
            $pdf->MultiCell(95, self::LINE, (string) $booking->booking, 'BR', 'C', false, 0, 10, '', true);
            $pdf->MultiCell(95, self::LINE, (string) $booking->house_bl, 'B', 'C', false, 0, 105, '', true);
            $pdf->Ln();
        }

        $pdf->SetTextColor(...self::RED);
        $pdf->Ln(7);
        $pdf->MultiCell(120, self::LINE, 'DESCRIPTION', 'BTR', 'C', true, 0, 10, '', true);
        $pdf->MultiCell(70, self::LINE, 'TOTAL DEBIT', 'LBT', 'C', true, 0, 130, '', true);
        $pdf->Ln();
        $y = $pdf->GetY();
        $pdf->MultiCell(120, 15, '', 'BR', 'C', false, 0, 10, '', true);
        $pdf->MultiCell(70, 15, '', 'B', 'C', false, 0, 130, '', true);
        $pdf->Ln(22);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(120, self::LINE, 'COURRIER', 'BTR', 'L', false, 0, 10, '', true);
        $pdf->MultiCell(70, self::LINE, $this->usd($total), 'BT', 'C', false, 0, 130, '', true);
        $pdf->Ln();
        $pdf->MultiCell(120, self::LINE, 'DUE TO DMF LOGISTICS DO BRASIL LTDA', 'BR', 'L', false, 0, 10, '', true);
        $pdf->MultiCell(70, self::LINE, $this->usd($half), 'B', 'C', false, 0, 130, '', true);
        $pdf->Ln();
        $pdf->MultiCell(120, self::LINE, 'DUE TO', 'BR', 'L', false, 0, 10, '', true);
        $pdf->MultiCell(70, self::LINE, $this->usd($half), 'B', 'C', false, 0, 130, '', true);
        $pdf->Ln(12);
        $pdf->MultiCell(120, self::LINE, 'TOTAL DUE TO DMF LOGISTICS DO BRASIL LTDA', 'BRT', 'L', true, 0, 10, '', true);
        $pdf->MultiCell(70, self::LINE, $this->usd($half), 'LBT', 'C', true, 0, 130, '', true);

        $pdf->MultiCell(180, 10, (string) $courier->nota, 0, 'L', false, 0, 30, 38, true);
        $pdf->MultiCell(180, 10, $agent, 0, 'L', false, 0, 30, 53, true);
        $pdf->MultiCell(35, self::LINE, (string) $courier->numero, 0, 'L', false, 0, 50, $y + self::LINE, true);
        $pdf->MultiCell(35, self::LINE, 'NUMBER', 0, 'L', false, 0, 15, $y + self::LINE, true);
        $pdf->MultiCell(25, self::LINE, $this->usd($total), 0, 'L', false, 0, 155, $y + self::LINE, true);

        $this->company($pdf);

        return $pdf;
    }

    private function company(TCPDF $pdf): void
    {
        $pdf->SetTextColor(...self::RED);
        $pdf->SetFont('Helvetica', '', self::FONT_SIZE);
        $pdf->MultiCell(80, 60, ' ', 'BTR', 'L', false, 0, 10, 225, true);
        $pdf->MultiCell(110, 60, ' ', 'BT', 'L', false, 0, 90, 225, true);
        $pdf->Ln();
        $pdf->MultiCell(95, self::LINE, 'DMF Logistics do Brasil Ltda', 0, 'L', false, 0, 15, 230, true);

        foreach (['Address: Rua Borges de Medeiros 274 2º Andar', 'Santa Cruz do Sul - RS - Brazil - zip: 96810-034', 'Phone:  +55(51) 3717-8200 ', 'Tax Id  07.052.088/0001-34  '] as $line) {
            $pdf->Ln(4);
            $pdf->MultiCell(95, self::LINE, $line, 0, 'L', false, 0, 15, '', true);
        }

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell(120, self::LINE, 'OUR BANK DETAILS:', 0, 'L', false, 0, 95, 230, true);

        foreach (self::BANK_DETAILS as $line) {
            $pdf->Ln(4);
            $pdf->MultiCell(120, self::LINE, $line, 0, 'L', false, 0, 95, '', true);
        }
    }

    private function usd(float $value): string
    {
        return 'USD '.number_format($value, 2, ',', '.');
    }
}
