<?php

namespace App\Actions\Booking\Documents;

use App\Models\Embarque;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * billingInstruction (legado, DmfUtil::geraPdfBilling): PDF A4 da instrução de cobrança da reserva, em inglês.
 * Os dados vêm de BillingInstruction. ponytail: título em Helvetica (o legado usa BebasNeue) e altura de linha
 * calculada pelo texto, em vez do 4 fixo que cortava observações longas.
 */
class BillingInstructionPdf
{
    private const FS = 8;

    private const RED = [165, 29, 33];

    public function __construct(private BillingInstruction $instruction) {}

    public function __invoke(Embarque $booking): StreamedResponse
    {
        $pdf = $this->render($booking);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), "billing_{$booking->booking}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render(Embarque $booking): TCPDF
    {
        $d = ($this->instruction)($booking);

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Billing Instructions');
        $pdf->SetSubject("Billing Instructions: {$d['type']}");
        $pdf->SetKeywords('DMF Billing Instructions');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setImageScale(4);
        $pdf->AddPage();
        $pdf->SetMargins(10, 10, 10, 10);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->SetDrawColor(...self::RED);

        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(...self::RED);
        $pdf->MultiCell(190, 5, "BILLING INSTRUCTIONS: {$d['type']}", 0, 'C', false, 1, 10, 20);
        $pdf->Ln(5);

        $this->pair($pdf, ['DMF Contact:', $d['contact']], ['Updated At:', $d['updated_at']]);
        $this->pair($pdf, ['Seller:', $d['seller']], ['Shipment Type:', $d['shipment']]);
        $pdf->Ln(4);
        $this->line($pdf, 'C/nee:', $d['consignee']);
        $this->line($pdf, 'Notify:', $d['notify']);
        $this->line($pdf, 'Shipper:', $d['shipper']);
        $this->line($pdf, 'Contacts:', $d['contacts']);
        $this->line($pdf, 'DMF Representation Agent:', $d['agent']);
        $this->line($pdf, $d['maritime'] ? 'Port of Origin:' : 'Airport of Origin:', $d['origin']);
        $this->line($pdf, $d['maritime'] ? 'Port of Destination:' : 'Airport of Destination:', $d['destination']);
        $this->line($pdf, 'Place of Delivery:', $d['delivery']);

        if ($d['maritime']) {
            $this->label($pdf, 45, 4, 'SS Line:', 'B');
            $this->value($pdf, 95, 4, $d['carrier'], 'LB');
            $this->label($pdf, 32, 4, 'Free Time Demurrage:', 'LB', 'L', 150);
            $this->value($pdf, 18, 4, (string) $d['free_time'], 'LB');
            $pdf->Ln();
        } else {
            $this->line($pdf, 'Air Company:', $d['carrier']);
        }

        $this->line($pdf, 'Booking:', $d['booking']);
        $this->line($pdf, 'Type and Quantity:', $d['goods']);
        $pdf->Ln(4);
        $this->pair($pdf, ['Inland:', $d['inland']], ['Tipo Fechamento:', $d['closing']]);
        $this->line($pdf, 'Icoterm:', $d['incoterm']);
        $this->line($pdf, 'Invoice:', $d['invoice']);
        $this->label($pdf, 45, 4, 'Customer Reference:', 'B');
        $this->value($pdf, 145, 4, $d['customer_ref'], 'LB');
        $this->label($pdf, 45, 4, 'Referência Cotação:', 'L', 'L', 110);
        $this->value($pdf, 45, 4, $d['quotation'], 0);
        $pdf->Ln();
        $this->line($pdf, 'Obs. Embarque:', $d['notes']);

        if ($d['special'] !== null) {
            $this->line($pdf, 'Instruções Especiais:', $d['special']);
        }

        $pdf->Ln(4);
        $this->costs($pdf, $d['costs']);
        $pdf->Ln(4);
        $this->closing($pdf, $d);

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(0);
        $pdf->MultiCell(190, 5, "Rua Borges de Medeiros, 274 - 2o. Andar\nSanta Cruz do Sul - RS - Brasil CEP: 96810-130\nPhone: +55 51 3717-8200 / FAX +55 51 3717-8224", 0, 'C', false, 1, '', 275, true);

        return $pdf;
    }

    /** Rótulo em vermelho e valor, na largura toda. */
    private function line(TCPDF $pdf, string $label, string $value): void
    {
        $pdf->SetFont('Helvetica', '', self::FS);
        $height = max(4, $pdf->getStringHeight(145, $value), $pdf->getStringHeight(45, $label));
        $this->label($pdf, 45, $height, $label, 'B');
        $this->value($pdf, 145, $height, $value, 'LB');
        $pdf->Ln();
    }

    /**
     * Dois rótulos com valor na mesma linha (esquerda e direita).
     *
     * @param  array{0: string, 1: string}  $left
     * @param  array{0: string, 1: string}  $right
     */
    private function pair(TCPDF $pdf, array $left, array $right): void
    {
        $pdf->SetFont('Helvetica', '', self::FS);
        $height = max(4, $pdf->getStringHeight(50, $left[1]), $pdf->getStringHeight(50, $right[1]));
        $this->label($pdf, 45, $height, $left[0], 'TBR');
        $this->value($pdf, 50, $height, $left[1], 'TB');
        $this->label($pdf, 45, $height, $right[0], 'LTBR');
        $this->value($pdf, 50, $height, $right[1], 'TB');
        $pdf->Ln();
    }

    /** @param  list<array{name: string, buying: string, selling: string, billing: string, due_to: string}>  $costs */
    private function costs(TCPDF $pdf, array $costs): void
    {
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $pdf->SetTextColor(...self::RED);

        foreach ([['Agreed Costs', 100], ['B/R', 25], ['S/R', 25], ['Billing Type', 20], ['Due To', 20]] as [$title, $width]) {
            $pdf->MultiCell($width, 4, $title, 1, 'C', true, 0, '', '', true);
        }

        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', self::FS);
        $pdf->SetTextColor(0);

        foreach ($costs as $cost) {
            $pdf->SetFont('Helvetica', '', self::FS);
            $height = max(4, $pdf->getStringHeight(100, $cost['name']));
            $pdf->MultiCell(100, $height, $cost['name'], 1, 'L', false, 0, '', '', true);
            $pdf->MultiCell(25, $height, $cost['buying'], 1, 'R', false, 0, '', '', true);
            $pdf->MultiCell(25, $height, $cost['selling'], 1, 'R', false, 0, '', '', true);
            $pdf->MultiCell(20, $height, $cost['billing'], 1, 'C', false, 0, '', '', true);
            $pdf->MultiCell(20, $height, $cost['due_to'], 1, 'C', false, 0, '', '', true);
            $pdf->Ln();
        }
    }

    /** Caução, câmbio, seguro, total (para preencher à mão), despachantes e demurrage. @param  array<string, mixed>  $d */
    private function closing(TCPDF $pdf, array $d): void
    {
        $this->label($pdf, 50, 4, 'Security Deposit', 1);
        $this->value($pdf, 20, 4, $d['deposit'], 1, 'C');
        $this->label($pdf, 50, 4, 'Currency Rate ', 0, 'C', 100);
        $this->label($pdf, 47, 4, 'Fee ', 0, 'C');
        $pdf->Ln();
        $this->value($pdf, 50, 4, 'USD', 1, 'L', 100);
        $this->value($pdf, 47, 4, 'R$', 1);
        $pdf->Ln();
        $this->label($pdf, 50, 4, 'Insurance', 1);
        $this->value($pdf, 20, 4, $d['insurance'], 1, 'C');
        $this->value($pdf, 50, 4, 'R$', 1, 'L', 100);
        $pdf->Ln(8);
        $this->label($pdf, 50, 4, 'TOTAL ', 0, 'C', 100);
        $pdf->Ln();
        $this->value($pdf, 50, 4, 'R$', 1, 'L', 100);
        $pdf->Ln(8);

        foreach ([['DMF Broker', $d['dmf_broker']], ['Customer Broker', $d['client_broker']], ['Customer Broker Email', $d['client_broker_email']]] as [$label, $value]) {
            $this->label($pdf, 50, 4, $label, 0);
            $this->value($pdf, 140, 4, $value, 0);
            $pdf->Ln(4);
        }

        foreach ($d['broker_contacts'] as $contact) {
            $this->value($pdf, 150, 4, $contact, 0, 'L', 57);
            $pdf->Ln(4);
        }

        if ($d['maritime']) {
            $pdf->Ln();
            $this->label($pdf, 50, 4, 'Demurrage:', 0);
            $pdf->Ln();
            $this->value($pdf, 10, 4, 'NO', 1);
            $this->value($pdf, 10, 4, ' ', 1);
            $this->value($pdf, 10, 4, 'DAYS', 1, 'L', 30);
            $this->value($pdf, 10, 4, ' ', 1);
        }
    }

    private function label(TCPDF $pdf, float $width, float $height, string $text, string|int $border, string $align = 'L', string|float $x = ''): void
    {
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $pdf->SetTextColor(...self::RED);
        $pdf->MultiCell($width, $height, $text, $border, $align, false, 0, $x, '', true);
    }

    private function value(TCPDF $pdf, float $width, float $height, string $text, string|int $border, string $align = 'L', string|float $x = ''): void
    {
        $pdf->SetFont('Helvetica', '', self::FS);
        $pdf->SetTextColor(0);
        $pdf->MultiCell($width, $height, $text, $border, $align, false, 0, $x, '', true);
    }
}
