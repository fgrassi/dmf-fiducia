<?php

namespace App\Actions\ShipCover;

use App\Models\Armador;
use App\Models\Embarque;
use App\Models\Navio;
use App\Models\Porto;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * capaNavio/gerarCapa (legado): a capa de navio da importação com as reservas marcadas (importador, booking, armador de
 * pagamento, equipamentos e uma coluna em branco para o draft), linhas em branco até completar 10 e o quadro de conferência.
 */
class ShipCoverPdf
{
    /** @param  Collection<int, Embarque>  $bookings */
    public function __invoke(Navio $ship, Porto $origin, Porto $destination, Collection $bookings): StreamedResponse
    {
        $pdf = $this->render($ship, $origin, $destination, $bookings);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'capa-de-navio.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** @param  Collection<int, Embarque>  $bookings */
    public function render(Navio $ship, Porto $origin, Porto $destination, Collection $bookings): TCPDF
    {
        $bookings->loadMissing(['cliente', 'agente', 'goods.equipment']);

        $voyages = $bookings->pluck('vg')->filter(fn ($vg) => strlen((string) $vg))->unique()->implode('/');
        $agents = $bookings->map(fn ($booking) => $booking->agente?->nome)->filter()->unique()->implode('/ ');

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Shipping Instructions');
        $pdf->SetSubject('Ocean Shipping Instructions');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 7);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->AddPage();
        $pdf->Image(resource_path('images/logo.png'), 15, 12, 30);

        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->SetXY(50, 10);
        $pdf->Cell(100, 5, 'DMF LOGISTICS DO BRASIL LTDA', 0, 1, 'C');
        $pdf->SetX(50);
        $pdf->Cell(100, 5, 'IMPORTAÇÃO', 0, 1, 'C');
        $pdf->SetY(40);

        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(180, 8, 'Porto de Desembarque: '.$destination->nome, 1, 1);
        $pdf->Cell(110, 8, 'Navio: '.$ship->nome, 1, 0);
        $pdf->Cell(70, 8, 'Vg.: '.$voyages, 1, 1);

        foreach (['Origem: '.$origin->nome, 'Previsão de Saída: ', 'Previsão de Chegada: ', 'Free Time / Armador: ', 'Free Time / Cliente: ', 'Agente: '.$agents] as $line) {
            $pdf->Cell(180, 8, $line, 1, 1);
        }

        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 12);
        foreach ([[65, 'Importador'], [35, 'Booking'], [30, 'Armador'], [25, 'Equip.'], [25, 'Draft Env.']] as [$width, $title]) {
            $pdf->Cell($width, 7, $title, 1, 0);
        }
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 12);
        $lines = 0;

        foreach ($bookings as $booking) {
            $equipment = $booking->goods->map(fn ($goods) => $goods->quantidade.' * '.$goods->equipment?->nome)->implode("\n");
            $lines += $booking->goods->count();
            $pdf->MultiCell(65, 18, strtoupper((string) $booking->cliente?->nome), 1, 'L', false, 0, maxh: 18);
            $pdf->MultiCell(35, 18, (string) $booking->booking, 1, 'L', false, 0, maxh: 18);
            $pdf->MultiCell(30, 18, (string) Armador::query()->whereKey($booking->paymentCarrierId())->value('nome'), 1, 'L', false, 0, maxh: 18);
            $pdf->MultiCell(25, 18, $equipment, 1, 'L', false, 0, maxh: 18);
            $pdf->MultiCell(25, 18, '', 1, 'L', false, 1, maxh: 18);
        }

        for ($blank = 0; $blank < 10 - $lines; $blank++) {
            foreach ([65, 35, 30, 25, 25] as $width) {
                $pdf->Cell($width, 12, '', 1, 0);
            }
            $pdf->Ln();
        }

        $pdf->Ln(2);
        foreach (['( ) Embarque confirmado', '( ) Documentação enviada ao despachante', '( ) Courier enviado ao Brasil', '( ) MBL original enviado ao despachante'] as $check) {
            $pdf->Cell(100, 5, $check, 0, 1);
        }

        return $pdf;
    }
}
