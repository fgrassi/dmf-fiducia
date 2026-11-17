<?php

namespace App\Actions\Booking\Bls;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/** cartaProtesto / CartaProtestoDocs (legado): a notificação de protesto dos containers avariados da reserva. */
class ProtestLetterPdf
{
    private const MONTHS = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function __invoke(Embarque $booking): StreamedResponse
    {
        $pdf = $this->render($booking);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), "carta_protesto_{$booking->booking}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function render(Embarque $booking): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Carta Protesto');
        $pdf->SetSubject(' Carta Protesto');
        $pdf->SetKeywords('DMF Shipping Air Instructions');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetMargins(10, 10, 10, 10);

        $pdf->Image(resource_path('images/logo_head_1.png'), 40, 15, 30);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(165, 29, 33);
        $pdf->MultiCell(0, 5, 'Notificação de Protesto', 0, 'L', false, 1, 75, 20);
        $pdf->Ln();
        $pdf->Ln();
        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->MultiCell(190, 5, 'Ref. Container Avariado', '', 'L', false, 1, 10, '', true);

        $bls = DB::table('bl')->where('embarque_id', $booking->getKey())->orderBy('bl_id')->get();
        $containers = DB::table('container')->whereIn('bl_id', $bls->pluck('bl_id'))->orderBy('container_id')->get()->groupBy('bl_id');

        foreach ($bls as $bl) {
            $pdf->MultiCell(190, 5, 'MASTER BL: '.$bl->mbl_number, '', 'L', false, 1, 10, '', true);
            $pdf->MultiCell(190, 5, 'HOUSE BL: '.$bl->bl, '', 'L', false, 1, 10, '', true);
            $pdf->MultiCell(190, 5, 'Containers:'.($containers[$bl->bl_id] ?? collect())->pluck('numero')->implode(', '), '', 'L', false, 1, 10, '', true);
        }

        $pdf->Ln();

        foreach ($bls as $bl) {
            foreach ($containers[$bl->bl_id] ?? [] as $container) {
                if (strlen((string) $container->observacao_avaria)) {
                    $pdf->MultiCell(190, 5, $container->numero.': '.$container->observacao_avaria, '', 'L', false, 1, 10, '', true);
                }
            }
        }

        $pdf->Ln();
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->writeHTMLCell(190, 0, 10, '', '<p>Prezado(a) Sr.(a)</p>'
            .'<p>Representando o embarcador da carga acima referenciada, informamos que a mesma foi recebida com danos por avarias informadas acima, ocorridos ainda sob sua responsabilidade, pelo o que os responsabilizamos pelas avarias e prejuizos advinds, servido este como PROTESTO FORMAL.</p>'
            .'<p>Assim, os convidamos a participar de vistoria particular, a ser realizada em data a ser definida, para verificação das avarias</p>', 0, 0, false, true, 'J', true);

        $pdf->Ln();
        $pdf->Ln(5);
        $pdf->Ln(5);
        $pdf->Ln(5);
        $pdf->MultiCell(190, 5, 'Santa Cruz do Sul, '.date('d').' de '.self::MONTHS[(int) date('n')].', '.date('Y'), '', 'C', false, 1, 10, '', true);

        $pdf->SetMargins(10, 10, 10, 10);
        $pdf->Image(resource_path('images/carimbo_diego.png'), 60, '', 0, 90);

        return $pdf;
    }
}
