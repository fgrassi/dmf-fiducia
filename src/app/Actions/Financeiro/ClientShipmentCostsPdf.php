<?php

namespace App\Actions\Financeiro;

use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * reports/pdfRelatorioCustosEmbarque (legado): "Levantamento de Custos de Embarques", PDF A4 paisagem com uma seção por
 * reserva (dados, itens de cada cobrança com taxa, valor unitário, índice e valor, total e dados do agente).
 */
class ClientShipmentCostsPdf
{
    private const PAGE_END = 184;

    /**
     * @param  array{client: string, shipments: list<array<string, mixed>>}  $report  saída de ClientShipmentCostsReport
     */
    public function __invoke(array $report, string $from, string $to): StreamedResponse
    {
        $pdf = $this->render($report, $from, $to);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'custos-embarques.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** @param  array{client: string, shipments: list<array<string, mixed>>}  $report */
    public function render(array $report, string $from, string $to): TCPDF
    {
        // customPdf do legado: "Página X/N" no topo e, no rodapé, título, direitos e "Página X/N"
        $pdf = new class('L', 'mm', 'A4', true) extends TCPDF
        {
            public function Header(): void
            {
                $this->SetFont('helvetica', 'B', 8);
                $this->Cell(0, 10, 'Página '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'R', false, '', 0, false, 'T', 'M');
            }

            public function Footer(): void
            {
                $this->SetFont('helvetica', 'B', 8);
                $this->Cell(70, 10, 'Relatório de Custos de Embarques', 0, false, 'L', false, '', 0, false, 'T', 'M');
                $this->Cell(50, 10, '© '.date('Y').' DMF Logistics do Brasil LTDA (www.dmflog.com.br)', 0, false, 'C', false, '', 0, false);
                $this->Cell(0, 10, 'Página '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'R', false, '', 0, false, 'T', 'M');
            }
        };
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Relatório de Custos de Embarques');
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->SetFooterMargin(10);
        $pdf->SetMargins(10, 10, 10, 10);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->AddPage();

        $pdf->Image(resource_path('images/logo_head_1.png'), 40, 15, 30);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(165, 29, 33);
        $pdf->MultiCell(150, 0, 'Levantamento de Custos de Embarques', 0, 'L', false, 1, 75, 20, true);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(190, 8, 'Cliente: '.$report['client'], 0, 'L', false, 1, 10, 40, true);
        $pdf->MultiCell(190, 8, 'Período: '.$this->date($from).' à '.$this->date($to), 0, 'L', false, 1, 10, null, true);

        $pdf->SetFont('Helvetica', 'B', 8);
        $this->cells($pdf, [[25, 'TIPO'], [35, 'RESERVA'], [60, 'ORIGEM'], [60, 'DESTINO'], [30, 'ETD'], [60, 'CTN']], 'C', 10);
        $pdf->Ln();

        foreach ($report['shipments'] as $shipment) {
            $this->shipment($pdf, $shipment);
        }

        return $pdf;
    }

    /** @param  array<string, mixed>  $s */
    private function shipment(TCPDF $pdf, array $s): void
    {
        $this->breakPage($pdf);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 8);

        $lines = $pdf->getNumLines($s['containers'], 60);
        $this->cells($pdf, [[25, $s['type']], [35, $s['booking']], [60, $s['origin']], [60, $s['destination']], [30, $this->date($s['etd'])], [60, $s['containers']]], 'C', 10, 4 * $lines);
        $pdf->Ln();

        $pdf->SetTextColor(165, 29, 33);
        $pdf->SetFont('Helvetica', 'B', 8);
        $this->cells($pdf, [[60, 'DESPESA'], [30, 'TAXA (MOEDA)'], [30, 'VALOR UNITÁRIO'], [30, 'ÍNDICE'], [30, 'VALOR DO ITEM']], 'C', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Ln();

        foreach ($s['charges'] as $charge) {
            $this->breakPage($pdf);
            $this->cells($pdf, [[180, 'Data de Pagamento: '.($charge['payment_date'] ? $this->date($charge['payment_date']) : 'NÃO PAGO')]], 'L', 10);
            $pdf->Ln();

            foreach ($charge['items'] as $item) {
                $this->breakPage($pdf);
                $this->item($pdf, $item['name'], $item['rate'], $item['symbol'].' '.number_format($item['unit'], 2, ',', '.'), $item['index'], $item['value']);
            }

            if ($charge['iof'] > 0) {
                $this->breakPage($pdf);
                $this->item($pdf, 'IOF', 1, 'R$ '.number_format($charge['iof'], 2, ',', '.'), 1, $charge['iof']);
            }
        }

        $pdf->SetFont('Helvetica', 'B', 8);
        $this->cells($pdf, [[150, 'TOTAL'], [30, 'R$ '.number_format($s['total'], 2, ',', '.')]], 'L', 10);
        $pdf->Ln();

        $pdf->SetTextColor(165, 29, 33);
        $this->cells($pdf, [[120, 'DADOS DO AGENTE'], [30, 'HBL'], [30, 'INVOICE']], 'C');
        $pdf->Ln();
        $this->breakPage($pdf);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 8);
        $agent = $s['agent'];

        if ($agent) {
            $this->cells($pdf, [[120, $agent['name']]], 'C');
        }

        $this->cells($pdf, [[30, $s['hbl']]], 'C', 130);
        $this->cells($pdf, [[30, $s['invoice']]], 'C', 160);
        $pdf->Ln();

        foreach ($agent ? [$agent['address'], $agent['country'], $agent['cnpj']] : [] as $line) {
            $this->cells($pdf, [[120, $line]], 'C');
            $pdf->Ln();
        }

        // o legado fechava o bloco com 1 linha em branco (4 mm) e, sem agente, com 4 (as linhas do agente ficavam vazias)
        $pdf->Ln($agent ? 4 : 16);
    }

    private function item(TCPDF $pdf, string $name, float|int $rate, string $unit, float|int $index, float|int $value): void
    {
        $lines = $pdf->getNumLines($name, 60);
        $this->cells($pdf, [[60, $name]], 'L', 10, 4 * $lines);
        $this->cells($pdf, [[30, number_format($rate, 4, ',', '.')]], 'C', null, 4 * $lines);
        $this->cells($pdf, [[30, $unit]], 'R', null, 4 * $lines);
        $this->cells($pdf, [[30, number_format($index, 4, ',', '.')]], 'C', null, 4 * $lines);
        $this->cells($pdf, [[30, 'R$ '.number_format($value, 2, ',', '.')]], 'R', null, 4 * $lines);
        $pdf->Ln();
    }

    /**
     * Células com borda lado a lado, a partir de $x (ou de onde o cursor está).
     *
     * @param  list<array{0: int|float, 1: string}>  $cells  [largura, texto]
     */
    private function cells(TCPDF $pdf, array $cells, string $align, ?float $x = null, float $height = 4): void
    {
        foreach ($cells as [$width, $text]) {
            $pdf->MultiCell($width, $height, $text, 1, $align, false, 0, $x ?? '', '', true);
            $x = null;
        }
    }

    private function breakPage(TCPDF $pdf): void
    {
        if ($pdf->GetY() > self::PAGE_END) {
            $pdf->AddPage();
        }
    }

    private function date(mixed $date): string
    {
        return filled($date) ? Carbon::parse($date)->format('d/m/Y') : '';
    }
}
