<?php

namespace App\Actions\Booking\ShippingInstruction;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use TCPDF;

/**
 * ShippingInstructionDocs (legado): PDF da instrução de embarque ao agente, marítima (Ocean) ou aérea (Air), com os dados da reserva
 * em caixas de posição fixa (mm, como o legado) e as páginas das exigências da alfândega brasileira. O título sai em Helvetica
 * negrito (o legado usa BebasNeue) e em uma célula só, porque a fonte é mais larga que a original.
 */
class ShippingInstructionPdf
{
    /** tipo_container_id do LCL: reserva só com LCL manda conferir o equipamento com o shipper. */
    private const LCL = 20;

    private const RED = [165, 29, 33];

    private const COMPANY = "DMF LOGISTICS DO BRASIL LTDA\nRua Borges de Medeiros, 274/201\nSanta Cruz do Sul - RS - Brasil CEP: 96810-130\nPH: +5 51 3717-8200 / FAX +55 51 3717-8224\nCNPJ: 07.052.088/0001-34\nCTC: IMPORT DEPARTMENT";

    public function render(Embarque $booking): TCPDF
    {
        $air = $booking->tipo_embarque !== 'm';
        $data = $this->data($booking, $air);

        $pdf = new class('P', 'mm', 'A4', true) extends TCPDF
        {
            public function Footer(): void
            {
                $this->SetY(-20);
                $this->SetFont('Helvetica', '', 7);
                $this->SetTextColor(165, 29, 33);
                $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().' of '.$this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
            }
        };

        $title = $air ? 'AIR' : 'OCEAN';
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Shipping Instructions');
        $pdf->SetSubject("{$title} Shipping Instructions");
        $pdf->SetKeywords("DMF Shipping {$title} Instructions");
        $pdf->setPrintHeader(false);
        $pdf->SetMargins(25, PDF_MARGIN_TOP, 25);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->AddPage();
        $pdf->SetMargins(10, 10, 10, 10);
        $pdf->Image(resource_path('images/logo_head_1.png'), 40, 15, 30);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(...self::RED);
        $pdf->MultiCell(120, 5, "{$title} SHIPPING INSTRUCTIONS - {$data->booking}", 0, 'L', false, 1, 75, 20);
        $pdf->SetDrawColor(...self::RED);
        $pdf->SetFont('Helvetica', '', 7);

        $this->boxes($pdf, $air);
        $this->values($pdf, $data);
        $air ? $this->airBody($pdf, $data) : $this->oceanBody($pdf, $data);

        $pdf->SetMargins(10, 10, 10, 10);
        $pdf->AddPage();
        $pdf->SetTextColor(...self::RED);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->MultiCell(0, 5, 'Instructions from Braziliam Custom Requests', 'LTR', 'L', false, 0, '', '', true);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->writeHTMLCell(0, $air ? 0 : 260, '', '', $air ? self::AIR_REQUESTS : self::OCEAN_REQUESTS, 'LRB', 0, 0, true, 'L', true);
        $pdf->Ln();

        if ($air) {
            $pdf->Ln(5);
        } else {
            $pdf->AddPage();
        }

        $pdf->writeHTMLCell(0, $air ? 0 : 265, '', '', $air ? self::AIR_GUIDELINES : self::OCEAN_GUIDELINES, 1, 0, 0, true, 'L', true);

        return $pdf;
    }

    private function data(Embarque $booking, bool $air): object
    {
        $party = fn (?int $id) => $id ? DB::table('pessoa as p')
            ->leftJoin('cidades as c', 'c.cidades_id', '=', 'p.cidades_id')
            ->leftJoin('paises as pa', 'pa.paises_id', '=', 'p.paises_id')
            ->where('p.pessoa_id', $id)
            ->first(['p.nome', 'p.endereco', 'p.cnpj', 'c.nome as city', 'pa.nome as country']) : null;
        $symbol = fn (?int $id) => (string) DB::table('cmn_currencies')->where('id', $id)->value('symbol');
        $enabled = fn ($flag) => ! in_array((string) $flag, ['', '0'], true);

        $contacts = DB::table('embarque_contato as ec')->join('usuario as u', 'u.usuario_id', '=', 'ec.usuario_id')
            ->where('ec.embarque_id', $booking->getKey())->where('u.status', 1)->orderBy('u.nome')
            ->get(['u.nome', 'u.email', 'u.fone'])
            ->map(fn ($u) => $u->nome.(filled($u->email) ? " ({$u->email})" : '').' '.$u->fone."\n")->implode('');

        $expenses = DB::table('fin_expenses_values as v')->leftJoin('fin_expenses as x', 'x.id', '=', 'v.ref_expense')
            ->where('v.ref_embarque', $booking->getKey())->orderBy('v.id')
            ->get(['x.name', 'v.ctrl', 'v.ctrl2', 'v.buying_rate', 'v.selling_rate', 'v.ref_currency_br', 'v.ref_currency_sr'])
            ->filter(fn ($v) => $enabled($v->ctrl) || $enabled($v->ctrl2))
            ->map(fn ($v) => (object) [
                'name' => (string) $v->name,
                'buying' => $enabled($v->ctrl) ? $symbol($v->ref_currency_br).' '.number_format((float) $v->buying_rate, 2, ',', '.') : null,
                'selling' => $enabled($v->ctrl2) ? $symbol($v->ref_currency_sr).' '.number_format((float) $v->selling_rate, 2, ',', '.') : null,
            ]);

        $goods = DB::table('mercadoria as m')->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->where('m.embarque_id', $booking->getKey())->orderBy('m.mercadoria_id')
            ->get(['m.nome', 'm.quantidade', 'm.tipo_container_id', 't.nome as type']);

        return (object) [
            'booking' => (string) $booking->booking,
            'invoice' => (string) $booking->invoice,
            'origin' => (string) DB::table('porto')->where('porto_id', $booking->porto_origem_id)->value('nome'),
            'destination' => (string) DB::table('porto')->where('porto_id', $booking->porto_destino_id)->value('nome'),
            'carrier' => (string) ($air ? DB::table('cia_aerea')->where('id', $booking->ref_cia_aerea)->value('name') : DB::table('armador')->where('armador_id', $booking->armador_id)->value('nome')),
            'freightStatus' => (string) $booking->observacaoint,
            'incoterm' => (string) $booking->incoterm_emb,
            'shipper' => (string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_cliente)->value('nome'),
            'contacts' => $contacts,
            'consignee' => $party($booking->pessoa_id_importador),
            'notify' => $party($booking->pessoa_id_notify),
            'expenses' => $expenses,
            'goods' => $goods,
            'goodsNames' => $goods->pluck('nome')->implode(', '),
            'onlyLcl' => $goods->every(fn ($g) => (int) $g->tipo_container_id === self::LCL),
        ];
    }

    private function boxes(TCPDF $pdf, bool $air): void
    {
        $pdf->MultiCell(190, 5, '', 'B', 'L', false, 1, 10, 30, true);

        foreach ([[95, 25, 'Shipper', 35], [95, 25, 'Consignee', 60], [95, 25, 'Notify', 85]] as [$w, $h, $label, $y]) {
            $pdf->MultiCell($w, $h, $label, 'BR', 'L', false, 1, 10, $y, true);
        }

        $pdf->MultiCell(95, 7.5, 'PO Number', 'BR', 'L', false, 1, 10, 110, true);
        $pdf->MultiCell(48, 7.5, $air ? 'Loading Airport' : 'POL', 'BR', 'L', false, 1, 10, 117.5, true);
        $pdf->MultiCell(48, 7.5, $air ? 'Discharge Airport' : 'POD', 'BR', 'L', false, 1, 57, 117.5, true);
        $pdf->MultiCell(95, 7.5, $air ? 'Airline' : 'SS Line', 'BR', 'L', false, 1, 10, 125, true);
        $pdf->MultiCell(95, 97.5, 'Attention', 'B', 'C', false, 1, 105, 35, true);
        $pdf->SetFillColor(209, 211, 212);

        if ($air) {
            $alt = 134;
            $pdf->MultiCell(190, 5, 'Description of Goods', 'B', 'C', false, 1, 10, $alt, true);
            $pdf->MultiCell(190, 40, 'IMPORTANT NOTICE', 'TB', 'L', false, 1, 10, $alt += 20, true);
            $pdf->MultiCell(190, 5, 'Freight Terms / Incoterm', 'TB', 'L', false, 1, 10, $alt += 30, true);
            $pdf->MultiCell(70, 30, 'Expense', 'BR', 'C', false, 1, 10, $alt += 5, true);
            $pdf->MultiCell(60, 30, 'Costs', 'BR', 'C', false, 1, 80, $alt, true);
            $pdf->MultiCell(60, 30, 'Selling Rate', 'B', 'C', false, 1, 140, $alt, true);
            $pdf->MultiCell(95, 30, 'Consignee on MAWB', 'BR', 'L', false, 1, 10, $alt += 30, true);
            $pdf->MultiCell(95, 15, 'Freight on MAWB', 'B', 'L', false, 1, 105, $alt, true);
            $pdf->MultiCell(95, 15, 'Special Notes', 'B', 'L', false, 1, 105, $alt + 15, true);

            return;
        }

        $pdf->MultiCell(190, 5, 'Equipment', 'B', 'C', false, 1, 10, 132.5, true);
        $pdf->MultiCell(95, 17, 'Type', 'R', 'C', false, 1, 10, 137.5, true);
        $pdf->MultiCell(95, 20, 'Quantity', 0, 'C', false, 1, 105, 137.5, true);
        $pdf->MultiCell(190, 5, 'Description of Goods', 'TB', 'C', false, 1, 10, 155, true);
        $pdf->MultiCell(190, 40, 'IMPORTANT NOTICE', 'TB', 'L', false, 1, 10, 170, true);
        $pdf->MultiCell(190, 5, 'Freight Terms / Incoterm', 'TB', 'L', false, 1, 10, 200, true);
        $pdf->MultiCell(90, 40, 'Expense', 'BR', 'C', false, 1, 10, 205, true);
        $pdf->MultiCell(40, 40, 'Costs', 'BR', 'C', false, 1, 100, 205, true);
        $pdf->MultiCell(60, 40, 'Selling Rate', 'B', 'C', false, 1, 140, 205, true);
        $pdf->MultiCell(95, 30, 'Consignee on MBL', 'BR', 'L', false, 1, 10, 245, true);
        $pdf->MultiCell(95, 15, 'Freight on MBL', 'B', 'L', false, 1, 105, 245, true);
        $pdf->MultiCell(95, 15, 'Special Notes', 'B', 'L', false, 1, 105, 260, true);
    }

    /** Dados das partes, portos e carga nas caixas. */
    private function values(TCPDF $pdf, object $data): void
    {
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(85, 5, $data->shipper, 0, 'L', false, 1, 15, 40, true);
        $pdf->MultiCell(85, 5, $data->contacts, 0, 'L', false, 1, 15, 43, true);

        foreach ([[$data->consignee, 65], [$data->notify, 90]] as [$party, $y]) {
            if (! $party) {
                continue;
            }

            $pdf->MultiCell(85, 5, (string) $party->nome, 0, 'L', false, 1, 15, $y, true);
            $pdf->MultiCell(85, 5, (string) $party->endereco, 0, 'L', false, 1, 15, $y + 3, true);
            $pdf->MultiCell(85, 5, $party->city.' - '.$party->country, 0, 'L', false, 1, 15, $y + 6, true);
            $pdf->MultiCell(85, 5, 'CNPJ: '.$party->cnpj, 0, 'L', false, 1, 15, $y + 9, true);
        }

        $pdf->MultiCell(0, 5, $data->invoice, 0, 'L', false, 1, 15, 113, true);
        $pdf->MultiCell(0, 5, $data->origin, 0, 'L', false, 1, 15, 120, true);
        $pdf->MultiCell(0, 5, $data->destination, 0, 'L', false, 1, 62, 120, true);
        $pdf->MultiCell(0, 5, $data->carrier, 0, 'L', false, 1, 15, 127.5, true);
        $pdf->MultiCell(90, 0, $data->freightStatus, 0, 'L', false, 1, 110, 40, true);
    }

    private function oceanBody(TCPDF $pdf, object $data): void
    {
        if ($data->onlyLcl) {
            $pdf->SetFont('Helvetica', 'B', 7);
            $pdf->MultiCell(190, 5, 'YOU MUST CHECK WITH SHIPPER THEIR REAL NEED FOR CONTAINER TYPE AND QUANTITY.', 0, 'C', true, 1, 10, 155, true);
            $pdf->MultiCell(190, 5, 'PLEASE INFORM CONTAINER NUMBER AS SOON AS SHIPPER PICK IT UP EMPTY AT THE PORT.', 0, 'C', true, 1, 10, 159, true);
            $pdf->SetFont('Helvetica', '', 7);
        }

        foreach ($data->goods as $i => $good) {
            $pdf->MultiCell(85, 5, strtoupper((string) $good->type), 0, 'R', false, 1, 15, 142.5 + $i * 3, true);
            $pdf->MultiCell(85, 5, (string) $good->quantidade, 0, 'L', false, 1, 110, 142.5 + $i * 3, true);
        }

        $pdf->MultiCell(180, 0, $data->goodsNames, 0, 'L', false, 1, 15, 161, true);
        $pdf->MultiCell(0, 5, 'Please note below, with immediate effect, new condition for shipments to Brazil. This new condition MUST BE INFORMED TO SHIPPER and mentioned clause MUST be shown on all HBLs:', 0, 'L', false, 1, 40, 172, true);
        $pdf->MultiCell(0, 5, ShippingInstructionMail::BRAZIL_CLAUSE, 0, 'L', false, 1, 40, 182, true);
        $pdf->MultiCell(0, 5, $data->incoterm, 0, 'L', false, 1, 40, 200, true);

        foreach ($data->expenses as $i => $expense) {
            $y = 211 + $i * 3;
            $pdf->MultiCell(80, 3, $expense->name, 0, 'L', false, 1, 15, $y, true);
            $expense->buying && $pdf->MultiCell(35, 3, $expense->buying, 0, 'R', false, 1, 105, $y, true);
            $expense->selling && $pdf->MultiCell(55, 3, $expense->selling, 0, 'R', false, 1, 145, $y, true);
        }

        $pdf->MultiCell(85, 20, self::COMPANY, 0, 'L', false, 1, 10, 248, true);
        $pdf->MultiCell(80, 5, 'Must be Statted accordingly', 0, 'L', false, 1, 115, 248, true);
        $pdf->MultiCell(80, 5, "MBL/HBL must be freighted as per brazilian custom requested \nMBL/HBL must show signatures \"by hand\" \nMBL/HBL must show the same date of loading", 0, 'L', false, 1, 115, 264, true);
    }

    private function airBody(TCPDF $pdf, object $data): void
    {
        if ($data->onlyLcl) {
            $pdf->MultiCell(190, 5, 'You must check with shipper their real need for container type and quantity', 0, 'C', true, 1, 10, 155, true);
        }

        $pdf->MultiCell(180, 0, $data->goodsNames, 0, 'L', false, 1, 15, 140, true);
        $pdf->MultiCell(0, 5, "The package must contain labels (tags) with cargo details, shipper and cnee information, invoice number at all sides of the package - this is mandatory. \nOne sheet of Original HAWB and MAWB must be submitted with the cargo.", 0, 'L', false, 1, 40, 160, true);
        $pdf->MultiCell(0, 5, $data->incoterm, 0, 'L', false, 1, 40, 185, true);

        foreach ($data->expenses as $i => $expense) {
            $y = 195 + $i * 3;
            $pdf->MultiCell(60, 5, $expense->name, 0, 'L', false, 1, 15, $y, true);
            $expense->buying && $pdf->MultiCell(55, 5, $expense->buying, 0, 'R', false, 1, 85, $y, true);
            $expense->selling && $pdf->MultiCell(55, 5, $expense->selling, 0, 'R', false, 1, 145, $y, true);
        }

        $pdf->MultiCell(85, 20, self::COMPANY, 0, 'L', false, 1, 10, 225, true);
        $pdf->MultiCell(80, 5, 'Must be Stated accordingly', 0, 'L', false, 1, 115, 225, true);
        $pdf->MultiCell(80, 5, "MAWB/HAWB must be freighted as per brazilian custom requested \nMAWB/HAWB must show signatures \"by hand\" \nMAWB/HAWB must show the same date of issuance.", 0, 'L', false, 1, 115, 238, true);
    }

    private const OCEAN_REQUESTS = <<<'HTML'
<h4>SISCOMEX CARGA (SISCARGA)</h4>
<p><font size=7>In accordance with the most recent regulation just published by Brazilian Authorities, the new automated manifest system - SISCOMEX CARGA (SISCARGA) -, was implemented on March 31, 2008. The objective of this system is to control all cargoes which have Brazil as ORIGIN or DESTINATION.</font></p>
<h4>BRAZILIAN SEAFREIGHT IMPORT </h4>
<p>DMF shall register all data below described into SISCOMEX CARGA (SISCARGA), 72 hours prior vessels call at the FIRST Brazilian port of discharge.<br/><br/>In this respect, shippers, consignees, DMF and international agents will have to work very close in order to make sure that the following information is correctly mentioned in addition to today's already informed data:</p>
<ul>
<li>Consignee's CNPJ (Brazilian Company Tax ID) + complete Name/Address </li>
<li>NCM / Harmonized Code of cargoes > at least the 4 first digits</li>
<li>Packaging Type </li>
<li>Cargo CBM > for both LCL and FCL shipments </li>
<li>Cargo Gross Weight ( for FCL's container tare and seal numbers to be shown separately)  => in Kilograms (Kgs)</li>
<li>B/Ls consigned 'TO ORDER' shall have the real / final consignee of cargo also informed to SISCARGA but 72 hr prior vessel's arrival at FIRST Brazilian port of discharge .</li>
</ul>
<p>If these deadlines are not accomplished or if wrong information is registered into SISCARGA, cargoes will be automatically put on hold by system and the possible penalties will be charged from the sender. </p>
<h4>CORRECTION ADVISES REQUEST FOR SHIPMENTS FROM OR TO BRAZIL</h4>
<p>In view of the recent established Brazilian customs system (SISCARGA) to make strict control over cargoes arriving/leaving Brazil, some severe policy of penalties for corrections have been established by them and consequently shipping lines and by consequence the freight forwarders, are forced to implement rules for correction advises requests AFTER the deadline expires.<br/><br/>Maximum deadline to request Corrections on manifests/BLs without penalties: IMPORT : Up to 5 days before vessel's arrival in the first Brazilian port of call<br/><br/>However, these rules can vary from ss line to ss line, as well as the values for the corrections.</p>
<p><font color=red>FOR CORRECTIONS ADVISES REQUESTED AFTER THE ABOVE DEAD LINES, THE SS LINES WILL REQUEST THE "LOI"<br/>(LETTER OF INDEMNITY) AND, IN CASE OF FINES APPLICABLE BY BRAZILIAN CUSTOMS, THAT MAY VARY UP TO<br/>USD 3,000.00, THEY WILL CHARGE IT FROM THE SHIPPER OR THEIR FREIGHT FORWARDER/AGENT!</font></p>
HTML;

    private const OCEAN_GUIDELINES = <<<'HTML'
<h3>BRAZIL DOCUMENT HANDLING GUIDELINES (IMPORTS):</h3>
<ul>
<li>Shipper/ Consignee Must be shown in full details</li>
<li>CNPJ number Must be shown under the name and address of Consignee </li>
<li>Notify Party Must not be left blank. 'Same as consignee' is acceptable.</li>
<li>For Bills of Lading consigned 'TO ORDER' is accepted but full address, contact information and the CNPJ of the  final consignee will have to be provided to Brazlian Customs at least 72 hours before the estimated time of vessel's arrival at the first Brazilian Port call. Non compliance with this requirement will provoke and  automatically blocking of the cargo by customs and possible delays in its clearance.</li>
<li>Sea waybill is not acceptable</li>
<li>Original MB/L and HB/L Must be issued</li>
<li>MB/L and HB/L Must be signed "by hand"</li>
<li>Brazilian customs request 2 ORIGINAL + 3 NON NEGOTIABLE of MBL</li>
<li>Cargo description Must be identical on MB/L and HB/L</li>
<li>General cargo description is not acceptable i.e. general goods or merchandize, FAK, general cargo, generic names or miscellaneous. </li>
<li>NCM number (Harmonized Code) Must be shown under description of goods, under each commodity description. </li>
<li> Shipment of Household goods/ used cars is only accepted under freight Prepaid terms</li>
<li> Container details (container#, tare, seal#, G.W., measurement, qty of packages) Must to be specified for each box </li>
<li> Ocean freight Has to be shown on b/l (Buying rate to be shown on MB/L, Selling rate to be shown on HB/L)</li>
<li> "shipped on board" on MBL / HBL and HBL must to show the same date.</li>
</ul>
HTML;

    private const AIR_REQUESTS = <<<'HTML'
<font size=8>
<ul>
<li>Consignee's CNPJ (Brazilian Company Tax ID) + complete Name/Address</li>
<li> Packaging Type </li>
<li> Cargo CBM </li>
<li> Cargo Gross Weight ( )  => in Kilograms (Kgs) and final weight can not be " rounded up"<br/>(any questions on this respect please contact us)</li>
<li>Original HAWB must show ORIGINAL 2 FOR CONSIGNEE and rated. Is mandatory to have freight stated at the documents otherwise cargo will be stuck at customs until documents are fixed which takes time (meaning warehouse fees) and penalties.</li>
<li>MAWB must show: CONSOLIDATION AS PER ATTACHED CARGO MANIFEST</li>
</ul>
<p>Handling information: </p>
<strong>ALL CARGO TENDERED FOR TRANSPORT IS SUBJECT TO INSPECTION AND INVOICE NUMBER PLUS IMPORT LICENCE NUMBER MUST BE TYPED ACCORDINGLY ON BOTH MAWB & HAWB. AS AGREED RATE CAN NEVER BE MENTIONED ON HAWB BECAUSE IT'S NOT ACCEPTED IN BRAZIL.</strong>
<br/><br/>
If these deadlines are not accomplished or if wrong information is registered into CUSTOMS SYSTEM  cargo will be automatically put on hold by system and the possible penalties will be charged TO FINAL CONSIGNEE!
<br/><br/>
INVOICE + PACKING LIST + ANY OTHER DOCUMENT PROVIDED BY SHIPPER...MUST BE SENT ALONG WITH CARGO IN AN ENVELOPE ATTACHED TO ORIGINAL AWB ADDRESSED TO DMF LOGISTICS. CONSOLIDATED CARGO AS PER ATTACHED MANIFEST MUST BE MENTIONED ON REMARKS (MAWB).
<br/><br/>
<strong>REMARKS:</strong> ALTHOUGH A SEPARATE HAWB WILL BE ISSUED, RATE TO BE DECLARED ON SAME MUST BE IN ACCORDANCE WITH THE RATES SOLD TO CUSTOMER.
</font>
HTML;

    private const AIR_GUIDELINES = <<<'HTML'
<h3>BRAZIL DOCUMENT HANDLING GUIDELINES (IMPORTS):</h3>
<ul>
<li>Shipper/ Consignee Must be shown in full details</li>
<li>CNPJ number Must be shown under the name and address of Consignee </li>
<li>Notify Party Must not be left blank. Same as consignee is acceptable.</li>
<li>MAWB and HAWB  Must be signed "by hand"</li>
<li>Brazilian customs request 2 ORIGINAL + 3 NON NEGOTIABLE of MAWB/HAWB</li>
<li> Cargo description Must be identical on MAWB and HAWB</li>
<li>General cargo description is not acceptable i.e. general goods or merchandize, FAK, general cargo, generic names or miscellaneous. </li>
<li>Cargo description details (total pcs/type of paclking, G.W / NET must be the same stated on shipping docs and in case of weight discrepancy (between docs provided by shipper and actual weight, <u><i>please notify us immediately and do not ship cargo with such discrepancy</i></u>, shipper must provide new set of docs if that is the case;  total cbm). </li>
</ul>
HTML;
}
