<?php

namespace App\Actions\PaymentOrders;

use App\Models\InstrucaoPagamento;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * pdfInstrucaoPagamento (legado): PDF A4 da instrução de pagamento ao armador, com a reserva, os equipamentos, as
 * despesas, o IOF e os dados bancários (ou "VIA BOLETO"). ponytail: título em Helvetica (o legado usa BebasNeue).
 */
class PaymentInstructionPdf
{
    private const RED = [165, 29, 33];

    private const LINE = 5;

    private const ROW = 7;

    public function __construct(private PaymentInstruction $instruction) {}

    public function __invoke(InstrucaoPagamento $instruction): StreamedResponse
    {
        $pdf = $this->render($instruction);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), str_replace('/', '-', $instruction->code()).'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render(InstrucaoPagamento $instruction): TCPDF
    {
        $d = ($this->instruction)($instruction);

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Instrução de Pagamento');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setImageScale(4);
        $pdf->AddPage();
        $pdf->SetMargins(10, 10, 10, 10);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->SetDrawColor(...self::RED);
        $pdf->Image(resource_path('images/logo_head_1.png'), 40, 15, 30);

        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(...self::RED);
        $pdf->MultiCell(0, 5, $d['title'], 0, 'L', false, 1, 75, 20);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->MultiCell(190, 5, $d['code'], 0, 'R', false, 1, 10, 10);
        $pdf->MultiCell(190, 5, '', 'B', 'L', false, 1, 10, 30, true);

        $pdf->SetFont('Helvetica', '', 7);
        $this->grid($pdf, [
            [['Data', $d['date'], 95], ['Reserva', $d['booking'], 95]],
            [['Cliente', $d['client'], 190]],
            [['MBL', $d['mbl'], 95], ['HBL', $d['hbl'], 95]],
            [[$d['free_time_label'], $d['free_time'], 95], ['Navio', $d['ship'], 95]],
            [['Porto de Origem', $d['origin'], 95], ['Porto de Destino', $d['destination'], 95]],
            [['ETD', $d['etd'], 95], ['ETA', $d['eta'], 95]],
        ], 35);

        $pdf->Ln(self::LINE);
        $this->section($pdf, 'Equipamentos', [['Equipamento', 95], ['Quantidade', 95]]);

        foreach ($d['equipment'] as $equipment) {
            $this->cells($pdf, [[$equipment['name'], 95, 'C'], [$equipment['quantity'], 95, 'C']]);
        }

        $pdf->Ln(self::LINE);
        $this->section($pdf, 'Despesas', [['Despesa', 95], ['B/R', 35], ['Fator', 25], ['Valor do Item', 35]]);

        foreach ($d['items'] as $item) {
            $this->cells($pdf, [[$item['expense'], 95, 'L'], [$item['buying'], 35, 'R'], [$item['rate'], 25, 'R'], [$item['value'], 35, 'R']]);
        }

        foreach ([['SUBTOTAL ', $d['subtotal']], ['Despesas Financeiras/IOF ', $d['iof']], ['TOTAL ', $d['total']]] as [$label, $value]) {
            $this->cells($pdf, [[$label, 130, 'L'], [$value, 60, 'R']]);
        }

        $this->carrier($pdf, $d);

        return $pdf;
    }

    /**
     * Quadros com o rótulo pequeno em vermelho e o valor logo abaixo da linha, nas posições do legado.
     *
     * @param  list<list<array{0: string, 1: string, 2: int}>>  $rows
     */
    private function grid(TCPDF $pdf, array $rows, float $y): void
    {
        foreach ($rows as $row) {
            $x = 10;

            foreach ($row as [$label, $value, $width]) {
                $pdf->SetTextColor(...self::RED);
                $pdf->MultiCell($width, self::ROW, $label, 'B'.($x === 10 && count($row) > 1 ? 'R' : ''), 'L', false, 0, $x, $y, true);
                $pdf->SetTextColor(0);
                $pdf->MultiCell($width - 10, self::LINE, $value, 0, 'L', false, 0, $x + 5, $y + 3, true);
                $x += $width;
            }

            $y += self::ROW;
        }

        $pdf->SetY($y);
    }

    /** @param  list<array{0: string, 1: int}>  $columns */
    private function section(TCPDF $pdf, string $title, array $columns): void
    {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(...self::RED);
        $pdf->MultiCell(190, self::LINE, $title, 'TB', 'C', false, 1, 10, '', true);

        foreach ($columns as $i => [$label, $width]) {
            $pdf->MultiCell($width, self::LINE, $label, $i === count($columns) - 1 ? 'B' : 'BR', 'C', false, 0, '', '', true);
        }

        $pdf->Ln();
    }

    /** @param  list<array{0: string, 1: int, 2: string}>  $cells */
    private function cells(TCPDF $pdf, array $cells): void
    {
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(0);
        $height = max(self::LINE, $pdf->getStringHeight($cells[0][1], $cells[0][0]));

        foreach ($cells as $i => [$text, $width, $align]) {
            $pdf->MultiCell($width, $height, $text, $i === count($cells) - 1 ? 'B' : 'BR', $align, false, 0, '', '', true);
        }

        $pdf->Ln();
    }

    /** @param  array<string, mixed>  $d */
    private function carrier(TCPDF $pdf, array $d): void
    {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(...self::RED);
        $pdf->MultiCell(190, self::ROW, 'Armador', 'T', 'C', false, 0, 10, 253, true);
        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell(180, self::LINE, $d['carrier'], 0, 'L', false, 1, 15, 255, true);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(...self::RED);
        $pdf->MultiCell(190, self::LINE, 'Dados Bancários', 'TB', 'C', false, 1, 10, 260, true);
        $y = $pdf->GetY();
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(0);

        if ($d['bank'] === null) {
            $pdf->MultiCell(165, self::LINE, 'VIA BOLETO', 0, 'L', false, 0, 15, $y + 2, true);

            return;
        }

        $labels = [['Banco', 10, 65, 'BR'], ['Agência', 75, 60, 'BR'], ['Conta', 135, 65, 'B']];
        $pdf->SetTextColor(...self::RED);

        foreach ($labels as [$label, $x, $width, $border]) {
            $pdf->MultiCell($width, 10, $label, $border, 'L', false, 0, $x, $y, true);
        }

        $pdf->MultiCell(65, 10, 'Código', 'BR', 'L', false, 0, 10, $y + 10, true);
        $pdf->MultiCell(125, 10, 'Observações', 'B', 'L', false, 0, 75, $y + 10, true);
        $pdf->SetTextColor(0);

        foreach ([[$d['bank']['bank'], 15, $y + 3], [$d['bank']['agency'], 80, $y + 3], [$d['bank']['account'], 140, $y + 3], [$d['bank']['code'], 15, $y + 13], [$d['bank']['notes'], 80, $y + 13]] as [$text, $x, $textY]) {
            $pdf->MultiCell(55, self::LINE, $text, 0, 'L', false, 0, $x, $textY, true);
        }
    }
}
