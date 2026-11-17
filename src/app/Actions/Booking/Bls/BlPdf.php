<?php

namespace App\Actions\Booking\Bls;

use App\Models\Bl;
use Illuminate\Support\Facades\DB;
use TCPDF;

/**
 * blPdfAction (legado): o Bill of Lading em PDF A4, no mesmo desenho (coordenadas em mm) do legado.
 *
 * - `preprinted`  (print=s): só o texto, sem logo, título nem rodapé, para o formulário pré-impresso; abre a impressão do PDF.
 * - `draft`: prévia timbrada, sem assinatura e sem "NON NEGOTIABLE".
 * - `original`: carimba "ORIGINAL" enquanto as impressões anteriores (`$previousOriginals`) forem menos de 3.
 * - `marksIcon`: o triângulo do campo marcas (Kuwait).
 */
class BlPdf
{
    private const FS = 7;

    private const WIDTH = 170;

    private const DEFAULT_TEXT = '“AS PER LOCAL PORT AUTHORITIES REGULATIONS, OR CUSTOMS AUTHORITIES MAY RELEASE THE CARGO TO NOTIFY PARTY OR CONSIGNEE AT DESTINATION WITHOUT PRODUCTION OF ORIGINAL BILL/S OF LADING AND NOT INFORMING THE AGENCIES, WE ARE NOT BE RESPONSIBLE FOR SUCH DELIVERY, AND DO NOT ACCEPT ANY LIABILITY”';

    private const DEFAULT_TEXT_WITH_DOT = '“AS PER LOCAL PORT AUTHORITIES REGULATIONS, OR CUSTOMS AUTHORITIES MAY RELEASE THE CARGO TO NOTIFY PARTY OR CONSIGNEE AT DESTINATION WITHOUT PRODUCTION OF ORIGINAL BILL/S OF LADING AND NOT INFORMING THE AGENCIES, WE ARE NOT BE RESPONSIBLE FOR SUCH DELIVERY, AND DO NOT ACCEPT ANY LIABILITY.”';

    private const CARRIER_TERMS = 'RECEIVED by the Carrier the Goods and shipped in apparent good order and condition, '
        .'unless otherwise stated herein, for transportation on board the ocean vessel mentioned herein or '
        .'any substituted vessel or on board the feeder vessel or other means of transportation(rail or truck),'
        .' as agreed, authorized or permitted herein and subject to all the terms and conditions appearing on the front '
        .'and reverse of this Bill of Lading to which the merchant agrees by accepting this Bill of Lading, and local privileges '
        .'and customers notwithstanding.  The particulars given above as stated by the condition, contents and value of the Goods '
        .'are unknown to the carrier. DELIVERY of the Goods will only be made on payment of all Freight and Charges. IN WITNESS whereof '
        .'one(1) original Bill of Lading has been signed if not otherwise stated above, the same being accomplished the other(s), '
        .'if any, to be void.  If required by the Carrier one(1) original Bill of Lading must be surrendered duly endorsed in exchange '
        .'for the Goods or delivery  order.Signed on behalf of the Carrier.';

    public function render(Bl $bl, bool $preprinted = false, bool $draft = false, bool $original = false, int $previousOriginals = 0, bool $marksIcon = false): TCPDF
    {
        $booking = DB::table('embarque')->where('embarque_id', $bl->embarque_id)->first();
        $containers = DB::table('container as c')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')
            ->where('c.bl_id', $bl->getKey())
            ->orderBy('c.container_id')
            ->get(['c.*', 't.nome as type']);
        $fs = self::FS;
        $w = self::WIDTH;

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetHeaderMargin(10);
        $pdf->SetFooterMargin(23);
        $pdf->AddPage();
        $pdf->SetMargins(20, 0);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->Ln(20);

        if (! $preprinted) {
            $pdf->Image(resource_path('images/logo.png'), 15, 10, 50);

            if (! $draft) {
                $pdf->Image(resource_path('images/assinatura_diego.png'), 160, 230, 30);
            }

            $pdf->SetTextColor(187, 49, 23);
            $pdf->SetFont('Helvetica', 'B', 17);
            $pdf->MultiCell(170, 0, $draft ? 'DRAFT' : 'OCEAN', 0, 'C', false, 1, '', 9, true);
            $pdf->Ln(4);
            $pdf->MultiCell(170, 0, 'BILL OF LADING ', 0, 'C', false, 1, '', 16, true);
        }

        $pdf->Ln(10);
        $pdf->SetTextColor(0, 0, 0);

        $yStart = 32;

        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(85, 0, 'SHIPPER / EXPORTER(COMPLETE NAME AND ADDRESS)', 1, 'L', false, 0, '', $yStart, true);
        $pdf->MultiCell(50, 0, 'BOOKING NR.:', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(35, 0, 'BILL OF LADING NR.:', 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', $fs);

        $shipperHeight = $this->height($pdf, 85, (string) $bl->shipper);
        $bookingHeight = $this->height($pdf, 50, (string) $booking->booking);
        $defaultHeight = $bookingHeight * 5;

        $pdf->MultiCell(85, max($shipperHeight, $defaultHeight), (string) $bl->shipper, 'LR', 'L', false, 0, '', '', true);
        $pdf->MultiCell(50, $bookingHeight, (string) $booking->booking, 'L', 'L', false, 0, '', '', true);
        $pdf->MultiCell(35, 0, (string) $bl->bl, 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(85, 0, 'MBL NUMBER:', 1, 'L', false, 0, 105, '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->MultiCell(85, max($shipperHeight, $defaultHeight) - ($bookingHeight * 2), (string) $bl->mbl_number, 1, 'L', false, 0, 105, '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', $fs);

        $rowHeight = max($this->height($pdf, 85, (string) $bl->consignee), $this->height($pdf, 85, (string) $bl->special_instr), $defaultHeight);

        $pdf->MultiCell(85, 0, 'CONSIGNEE(NOT NEGOTIABLE UNLESS CONSIGNED TO ORDER)', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(85, 0, 'SPECIAL INSTRUCTIONS', 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->MultiCell(85, $rowHeight, (string) $bl->consignee, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(85, $rowHeight, (string) $bl->special_instr, 1, 'L', false, 0, '', '', true);
        $pdf->Ln();

        $y = $pdf->GetY();
        $pdf->SetFont('Helvetica', '', $fs);

        $h = max($this->height($pdf, 43, (string) $bl->dmf_ref), $this->height($pdf, 42, (string) $bl->port_lading));
        $h2 = max($this->height($pdf, 43, (string) $bl->vessel), $this->height($pdf, 42, (string) $bl->port_trans));
        $h3 = max($this->height($pdf, 43, (string) $bl->port_disc), $this->height($pdf, 42, (string) $bl->place_delivery));
        $h4 = max($this->height($pdf, 85, (string) $bl->representation), $defaultHeight);

        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(85, 0, 'NOTIFY(COMPLETE NAME AND ADDRESS)', 'LRB', 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->MultiCell(85, $h4, (string) $bl->notify, 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(43, 0, 'DMF REF:', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(42, 0, 'PORT OF LOADING', 1, 'L', false, 0, '', '', true);
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->MultiCell(43, $h, (string) $bl->dmf_ref, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(42, $h, (string) $bl->port_lading, 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(43, 0, 'VESSEL', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(42, 0, 'PORT OF TRANSSHIPMENT', 1, 'L', false, 0, '', '', true);
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->MultiCell(43, $h2, (string) $bl->vessel, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(42, $h2, (string) $bl->port_trans, 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(43, 0, 'PORT OF DISCHARGE', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(42, 0, 'PLACE OF DELIVERY', 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', $fs);

        $pdf->MultiCell(43, $h3, (string) $bl->port_disc, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(42, $h3, (string) $bl->place_delivery, 1, 'L', false, 0, '', '', true);
        $pdf->Ln();
        $x = $pdf->GetY();

        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(85, 0, 'TO EXCHANGE DOCUMENTS PLEASE CONTACT', 1, 'L', false, 0, 105, $y, true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->MultiCell(85, $h4, (string) $bl->representation, 1, 'L', false, 0, 105, '', true);
        $pdf->Ln();

        $h5 = $x - $pdf->GetY();
        $pdf->SetFont('Helvetica', 'B', 10);

        if ($original && $previousOriginals >= 0 && $previousOriginals < 3) {
            $pdf->MultiCell(85, $h5, 'ORIGINAL', 'TLR', 'C', false, 0, 105, '', true, 0, false, true, $h5, 'M');
        } else {
            $pdf->MultiCell(85, $h5, $draft ? '' : 'NON NEGOTIABLE', 'TLR', 'C', false, 0, 105, '', true, 0, false, true, $h5, 'M');
        }

        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->MultiCell(170, 0, 'PARTICULARS FURNISHED BY SHIPPER', 1, 'C', false, 0, '', $x, true);
        $pdf->Ln();
        $pdf->MultiCell(29, 0, 'MARK & NOS/CONTAINER', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(16, 0, 'No. OF PKGS', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(85, 0, 'DESCRIPTION OF PACKAGES AND GOODS', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(20, 0, 'GROSS WEIGHT', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(20, 0, 'MEASUREMENT', 1, 'L', false, 0, '', '', true);
        $pdf->Ln();

        $y = $pdf->GetY();
        $blockHeight = 250 - $y;
        $blockStart = $y;

        $pdf->SetFont('Helvetica', '', 5.5);
        $pdf->MultiCell(29, $blockHeight, (string) $bl->mark_nos, 'L', 'L', false, 0, '', $y, true);

        $marksHeight = $this->height($pdf, 29, (string) $bl->mark_nos);
        $iconY = $pdf->GetY() + $marksHeight + 2;

        if ($marksIcon) {
            $pdf->Image(resource_path('images/marks_number.png'), 22, $iconY, 25);
        }

        $pdf->SetFont('Helvetica', '', 6);
        $pdf->MultiCell(16, 0, (string) $bl->num_pkgs, '', 'L', false, 0, 50, $y, true);

        $grossWeight = number_format($this->number($bl->gross_weight), 3, ',', '.');
        $netWeight = number_format($this->number($bl->net_weight), 3, ',', '.');
        $volume = number_format($this->number($bl->volume), 4, ',', '.');

        $pdf->MultiCell(20, $blockHeight, $volume, 'R', 'C', false, 0, 170, $y, true);
        $pdf->MultiCell(20, 0, $grossWeight, '0', 'C', false, 0, 150, $y, true);
        $pdf->SetY($y);
        $pdf->Ln();
        $pdf->MultiCell(20, 0, 'KGS', '0', 'C', false, 0, 150, '', true);
        $pdf->MultiCell(20, 0, 'CBM', '0', 'C', false, 0, 170, '', true);
        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->Ln(10);
        $pdf->MultiCell(20, 0, 'NET WEIGHT', 1, 'C', false, 0, 150, '', true);
        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->MultiCell(20, 0, $netWeight, 0, 'C', false, 0, 150, '', true);
        $pdf->Ln(5);
        $pdf->MultiCell(20, 0, 'KGS', 0, 'C', false, 0, 150, '', true);
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 5.5);

        $description = str_replace([self::DEFAULT_TEXT_WITH_DOT, self::DEFAULT_TEXT], '', (string) $bl->desc_pkgs);

        $newBlockY = 230;
        $defaultTextHeight = $this->height($pdf, 85, self::DEFAULT_TEXT);

        $lines = $this->breakString($description, 70);
        $pdf->SetY($y);

        $heightLimit = 225;
        $maxLines = (int) floor(floor($heightLimit - $blockStart) / 2.5);
        $lineHeight = 2.5;

        $pdf->SetFont('Helvetica', '', 5.8);

        $overflow = count($lines) > $maxLines;
        $count = 1;

        foreach ($lines as $line) {
            if ($count++ <= $maxLines) {
                $pdf->MultiCell(85, $lineHeight, $line, 0, 'L', false, 0, 65, '', true, 0);
                $pdf->Ln();
            }
        }

        $showDefaultTextOnAttachment = false;

        if ($pdf->GetY() + $defaultTextHeight < $heightLimit) {
            $pdf->MultiCell(85, $defaultTextHeight, self::DEFAULT_TEXT, 0, 'L', false, 0, 65, '', true);
            $pdf->Ln($defaultTextHeight);
        } else {
            $showDefaultTextOnAttachment = true;
        }

        $containersHeight = 10;
        foreach ($containers as $container) {
            $containersHeight += 4 + (strlen((string) $container->seals_n2) ? 1.5 : 0) + (strlen((string) $container->seals_n3) ? 1.5 : 0);
        }

        if ($pdf->GetY() + $containersHeight < $heightLimit) {
            $this->containersBlock($pdf, $containers);
        } else {
            $overflow = true;
        }

        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(85, 0, '*APPLICABLE ONLY WHEN USED AS TROUGH BILL OF LADING', 1, 'L', false, 0, '', $newBlockY, true);

        $pdf->SetFont('Helvetica', '', 4.5);
        $termsHeight = $this->height($pdf, 85, self::CARRIER_TERMS);
        $difference = $this->height($pdf, 85, '*APPLICABLE ONLY WHEN USED AS TROUGH BILL OF LADING') + $this->height($pdf, 85, 'FREIGHT AND CHARGES');

        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(85, 0, 'FREIGHT AND CHARGES', 1, 'L', false, 0, '', '', true);
        $pdf->Ln();

        $observation = (string) $bl->observation;
        $alt1 = 0;
        $alt2 = 0;

        if (strlen($observation)) {
            $alt2 = $this->height($pdf, 85, $observation);
        }

        $freightY = $pdf->GetY();

        $pdf->SetFont('Helvetica', '', 4.5);
        $pdf->MultiCell(85, $termsHeight + 2.5, self::CARRIER_TERMS, 'TRLB', 'L', false, 0, 105, $newBlockY, true);
        $pdf->Ln();

        $yA = $pdf->GetY();
        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->MultiCell(85, $termsHeight + $bookingHeight + $alt2 - $difference, (string) $bl->obs, 'RLB', 'L', false, 0, '', $freightY, true);
        $pdf->SetY($yA + $alt2 + $bookingHeight);

        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(43, 0, 'FREIGHT PAYABLE AT', 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(42, 0, 'NUMBER OF ORIGINAL(B)/L', 1, 'L', false, 0, '', '', true);

        $freightAt = (string) $bl->freight_at;
        $originals = (string) $bl->num_original;
        $placeDate = (string) $bl->place_date;

        if (strlen($freightAt)) {
            $alt1 = $this->height($pdf, 43, $freightAt);
        }

        if (strlen($originals)) {
            $alt2 = $this->height($pdf, 43, $originals);
        }

        $alt = max($alt1, $alt2);

        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->MultiCell(43, $alt, $freightAt, 1, 'L', false, 0, '', '', true);
        $pdf->MultiCell(42, $alt, $originals, 1, 'L', false, 0, '', '', true);

        $pdf->SetY($yA);
        $pdf->MultiCell(85, 0, 'PLACE AND DATE OF ISSUE', 1, 'L', false, 0, 105, '', true);
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', $fs);

        if ($bl->shipped_board) {
            $date = (string) $bl->shipped_board;
        } else {
            $date = $booking->saida_efetiva ? date('M jS Y', strtotime($booking->saida_efetiva)) : null;
        }

        if ($placeDate === 'Santa Cruz do Sul, ') {
            $pdf->MultiCell(85, $alt, 'Santa Cruz do Sul, '.$date, 1, 'L', false, 0, 105, '', true);
        } else {
            $pdf->MultiCell(85, $alt, $placeDate, 1, 'L', false, 0, 105, '', true);
        }
        $pdf->Ln();

        $pdf->SetFont('Helvetica', 'B', $fs);
        $pdf->MultiCell(85, $bookingHeight, 'SHIPPED ON BOARD: '.$date, 1, 'L', false, 0, 105, '', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', $fs);

        $pdf->MultiCell(85, $alt2, $observation, 1, 'L', false, 0, 105, '', true);

        if (! $preprinted) {
            $pdf->SetTextColor(187, 49, 23);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->setCellHeightRatio(0.9);
            $pdf->MultiCell($w, 0, 'DMF LOGISTICS DO BRASIL', 0, 'C', false, 1, '', 277, true);
            $pdf->MultiCell($w, 0, 'Rua Borges de Medeiros, 274 - 2o. Andar - Ed. Leonardo da Vinci', 0, 'C', false, 1, '', '', true);
            $pdf->MultiCell($w, 0, 'Santa Cruz do Sul - RS - Brasil  - CEP: 96810-034', 0, 'C', false, 1, '', '', true);
            $pdf->MultiCell($w, 0, 'PABX: +55 51 3717-8200 - FAX: +55 51 3717-8224', 0, 'C', false, 1, '', '', true);
            $pdf->MultiCell($w, 0, 'www.dmflog.com.br - dmf@dmflog.com.br', 0, 'C', false, 1, '', '', true);
            $pdf->setCellHeightRatio(1);
        }

        if ($overflow) {
            $this->attachment($pdf, $lines, $maxLines, $containers, $showDefaultTextOnAttachment, $defaultTextHeight, $preprinted, $draft);
        }

        if ($pdf->getNumPages() > 1) {
            $attachment = 0;

            for ($page = 2; $page <= $pdf->getNumPages(); $page++) {
                $pdf->setPage($page);
                $pdf->MultiCell(180, 0, 'ATTACHMENT NO.: '.++$attachment.' 0F '.($pdf->getNumPages() - 1).' - '.$bl->bl, 0, 'R', false, 0, 10, 9, false);
            }
        }

        if ($preprinted) {
            $pdf->IncludeJS('print();');
        }

        return $pdf;
    }

    /** Segunda página: o que não coube na descrição e os containers, em tabela maior. */
    private function attachment(TCPDF $pdf, array $lines, int $maxLines, $containers, bool $showDefaultText, float $defaultTextHeight, bool $preprinted, bool $draft): void
    {
        $signature = fn () => $pdf->Image(resource_path('images/assinatura_diego.png'), 160, 230, 30);

        $pdf->AddPage();
        $pdf->SetMargins(20, 10);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(165, 0, 'DESCRIPTION OF PACKAGES AND GOODS', 0, 'C', false, 0, '', 20, true);
        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', '', 5.5);

        $x = $maxLines;
        while ($x < count($lines)) {
            $pdf->MultiCell(165, 1, $lines[$x++], 0, 'L', false, 1, 65, '', true);

            if ($x == 232) {
                if (! $draft) {
                    $signature();
                }

                $pdf->AddPage();
                $pdf->SetMargins(20, 10);
                $pdf->SetAutoPageBreak(true, 7);
                $pdf->SetFont('Helvetica', 'B', 10);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->MultiCell(165, 0, 'DESCRIPTION OF PACKAGES AND GOODS', 0, 'C', false, 0, '', '', true);
                $pdf->Ln(10);
                $pdf->SetFont('Helvetica', '', 5.5);
            }
        }

        if ($showDefaultText) {
            $pdf->Ln(2);
            $pdf->MultiCell(85, $defaultTextHeight, self::DEFAULT_TEXT, 0, 'L', false, 0, 65, '', true);
            $pdf->Ln($defaultTextHeight);
        }
        $pdf->Ln(2);

        if (! $preprinted && ! $draft) {
            $signature();
        }

        if ($pdf->getPage() == 1) {
            $pdf->AddPage();
            $pdf->SetMargins(20, 10);
            $pdf->SetAutoPageBreak(true, 7);
        }

        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(165, 0, 'CONTAINERS', 0, 'C', false, 0, '', '', true);
        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', 'B', 5);

        $widths = [20, 20, 15, 25, 25, 20, 20, 20];
        foreach (['Size/Type', 'No.', 'Tara', 'Seals no.', 'Qty of Packages', 'Gross Weight', 'Net Weight', 'Volume'] as $i => $title) {
            $pdf->MultiCell($widths[$i], 0, $title, 0, 'C', false, 0, '', '', true);
        }
        $pdf->Ln();

        $this->containerRows($pdf, $containers, $widths, '');

        if (! $preprinted && ! $draft) {
            $signature();
        }
    }

    /** addContainersBl: a tabelinha dentro do campo de descrição, na primeira página. */
    private function containersBlock(TCPDF $pdf, $containers): void
    {
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(85, 5, 'CONTAINERS', 0, 'C', false, 0, 65, '', true);
        $pdf->Ln(5);

        $pdf->SetFont('Helvetica', 'B', 5);
        $widths = [8, 14.5, 8, 15.1, 10.6, 10, 10, 8.6];
        foreach (['Size / Type', 'No.', 'Tara', 'Seals no.', 'Qty of Packages', 'Gross Weight', 'Net Weight', 'Volume'] as $i => $title) {
            $pdf->MultiCell($widths[$i], 5, $title, 0, 'C', false, 0, $i === 0 ? 65 : '', '', true);
        }
        $pdf->Ln(5);

        $this->containerRows($pdf, $containers, $widths, 65);
    }

    private function containerRows(TCPDF $pdf, $containers, array $widths, int|string $x): void
    {
        foreach ($containers as $container) {
            $pdf->SetFont('Helvetica', '', 5);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetTextColor(0);

            $height = 4 + (strlen((string) $container->seals_n2) ? 1.5 : 0) + (strlen((string) $container->seals_n3) ? 1.5 : 0);
            $seals = $container->seals_n1."\n".$container->seals_n2."\n".$container->seals_n3;

            $cells = [$container->type, $container->numero, $container->tara, $seals, $container->qty_pkgs, $container->gross_weight, $container->net_weight, $container->volume];

            foreach ($cells as $i => $text) {
                if ($i === 3) {
                    $pdf->SetFont('Helvetica', '', 4.5);
                } elseif ($i === 4) {
                    $pdf->SetFont('Helvetica', '', 5);
                }

                $pdf->MultiCell($widths[$i], $height, (string) $text, 1, 'C', $i === 0, 0, $i === 0 ? $x : '', '', true, 0, false, true, $height, 'M', false);
            }

            $pdf->Ln();
        }
    }

    /** DMFUtil::getStringHeight: altura que o texto ocupa numa célula de largura $w, medida numa cópia do PDF. */
    private function height(TCPDF $pdf, float $w, string $text): float
    {
        $copy = clone $pdf;
        $copy->AddPage();
        $copy->SetY(0);
        $copy->MultiCell($w, 3, $text);

        return ceil($copy->GetY());
    }

    /** DmfUtil::trocaVirgula: "1.234,5" => 1234.5. */
    private function number(?string $value): float
    {
        return (float) str_replace(',', '.', str_replace('.', '', (string) $value));
    }

    /**
     * DmfUtil::breakString: quebra o texto em linhas de até $max caracteres, sem partir palavras.
     *
     * @return list<string>
     */
    private function breakString(string $text, int $max): array
    {
        $result = [];

        foreach (explode("\n", $text) as $line) {
            if (strlen(trim($line)) <= $max) {
                $result[] = trim($line);

                continue;
            }

            $part = '';

            foreach (explode(' ', $line) as $word) {
                if (strlen($part.$word.' ') < $max) {
                    $part .= $word.' ';
                } else {
                    $result[] = $part;
                    $part = $word.' ';
                }
            }

            $result[] = $part;
        }

        return $result;
    }
}
