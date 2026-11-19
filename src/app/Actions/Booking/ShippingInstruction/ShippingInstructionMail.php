<?php

namespace App\Actions\Booking\ShippingInstruction;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/**
 * Assunto e HTML do e-mail da instrução de embarque ao agente (enviaInstrucaoEmbarque do legado), em inglês: marítimo com as
 * cláusulas do HBL para o Brasil, aéreo com as exigências do AWB. Os valores digitados vão escapados.
 */
class ShippingInstructionMail
{
    public const BRAZIL_CLAUSE = '"NOTWITHSTANDING ANY PROVISION TO THE CONTRARY, THE MERCHANT AGREES THAT IF THIS BILL OF LADING PROVIDES FOR DELIVERY OF THE GOODS AT A PORT IN BRAZIL, DISCHARGE OF THE GOODS TO ANY PORT AUTHORITY AT SUCH PORT SHALL CONSTITUTE DUE DELIVERY HEREUNDER AND ALL LIABILITY OF THE CARRIER WHATSOEVER IN CONNECTION WITH THE GOODS (INCLUDING WITHOUT LIMITATION FOR MISDELIVERY) SHALL CEASE AT THAT TIME. CARRIER SHALL NOT BE RESPONSIBLE FOR DELIVERY OF CARGO WITHOUT THE PRESENTATION OF THE ORIGINAL BILL OF LADING, AS BRAZILIAN CUSTOMS REGULATIONS."';

    /** tipo_container_id do LCL. */
    private const LCL = 20;

    /**
     * @param  string  $names  nomes dos contatos do agente
     * @param  string  $notes  instruções digitadas na tela para os agentes
     * @return array{subject: string, html: string}
     */
    public function __invoke(Embarque $booking, string $names, string $notes, string $senderName, string $senderEmail): array
    {
        $origin = (string) DB::table('porto')->where('porto_id', $booking->porto_origem_id)->value('nome');
        $shipper = (string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_cliente)->value('nome');
        $consignee = (string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_importador)->value('nome');
        $message = '<p>Dear '.e($names).'</p>';

        if ($booking->tipo_embarque === 'm') {
            $subject = "DMF - S.I. {$booking->booking} ({$origin}) - {$shipper} / {$consignee} - {$booking->invoice}";
            $body = $message.$this->ocean($booking, $notes, $senderName, $senderEmail);
            $title = "Ocean Shipping Instruction {$booking->booking}({$origin})";
            $background = 'background-color:#e6e7e9;';
        } else {
            $subject = "DMF LOGISTICS DO BRASIL - AIR SHIPPING INSTRUCTIONS {$booking->booking}({$origin}) SHIPPER: {$shipper} //CNEE: {$consignee} - {$booking->invoice}";
            $body = $message.self::AIR;
            $title = "Air Shipping Instruction {$booking->booking}({$origin})";
            $background = '';
        }

        return ['subject' => $subject, 'html' => $this->wrap($title, $body, $background)];
    }

    private function ocean(Embarque $booking, string $notes, string $senderName, string $senderEmail): string
    {
        $html = '';

        if ($booking->impor_expor === 'i' && (int) $booking->ctrl_autorizacao_impo === 2) {
            $html .= '<h2><span style="color:red">SHIPMENT NOT AUTHORIZED, PLEASE WAIT OUR INSTRUCTIONS.</span></h2>';
        }

        $html .= '<h3>SPECIAL INSTRUCTIONS</h3>'
            .'<h3>FROM NOW ON, PLEASE CHANGE FROM “PROFIT SHARE” TO “HANDLING FEE” AT YOUR INVOICES (DEBIT NOTE / CREDIT NOTE).</h3>';

        if (filled($notes)) {
            $html .= '<p><span style="background-color: yellow">'.nl2br(e($notes)).'</span></p>';
        }

        $html .= '<p>Please note attached our Ocean Shipping Instructions <span style="background-color: yellow">'.e($booking->booking).'</span>.</p>'
            .'<p>Kindly contact shipper and, as soon as possible, provide us the following booking details:</p>'
            .'<ul><li>Vessel Name:</li><li>Voyage:</li><li>Cut Off(docs / cargo):</li><li>ETD:</li><li>ETA:</li></ul>';

        if (filled($booking->local_retirada_mercadoria)) {
            $html .= '<span>Pick Up Address</span><p>'.nl2br(e($booking->local_retirada_mercadoria)).'</p>';
        }

        $onlyLcl = DB::table('mercadoria')->where('embarque_id', $booking->getKey())->where('tipo_container_id', '<>', self::LCL)->doesntExist();

        if ($onlyLcl) {
            $html .= '<p>YOU MUST CHECK WITH SHIPPER THEIR REAL NEED FOR CONTAINER TYPE AND QUANTITY.</p>'
                .'<p>PLEASE INFORM CONTAINER NUMBER AS SOON AS SHIPPER PICK IT UP EMPTY AT THE PORT.</p>';
        }

        return $html.'<p>If any unclear, please let us to know.</p>'
            .'<center><hr/><h1>VERY IMPORTANT: UPDATED clauses on HBL for shipments to Brazil</h1><hr/></center>'
            .'<span style="background-color: yellow"><u>1st Clause:</u></span>'
            .'<p>Please note below, with immediate effect, new condition for shipments to Brazil. This new condition MUST BE INFORMED TO SHIPPER and mentioned clause MUST be shown on all HBLs:</p>'
            .'<p>'.self::BRAZIL_CLAUSE.'</p>'
            .'<span style="background-color: yellow"><u>2nd Clause:</u></span>'
            .'<p>Please kindly issue the HBL\'s drafts for future shipment always reading, before the description of goods, the following: "Shipper\'s load & count said to contain:"</p>'
            .'<p><u>For example:</u></p><p>Shipper\'s load & count said to contain:</p><ul><li>900 packages of tires</li></ul>'
            .'<p> Best regards, </p><p><strong>'.e($senderName).'</strong></p>'
            .'<p><strong>'.e($senderEmail).' - DMF Logistics do Brasil</strong></p>'
            .'<p><strong>www.dmflog.com.br - +55(51) 3717-8200</strong></p>';
    }

    private function wrap(string $title, string $body, string $background): string
    {
        $images = rtrim((string) config('services.tracking.url'), '/').'/images/email';

        return '<div class="td_c"><div class="td_l"><div class="titulo">'.e($title).'</div></div><div class="td_r"></div><div class="limpar"></div>'
            .'<div class="box-content_emb">'.$body.'</div></div>'
            .'<style>.box-content_emb{border-radius:10px;'.$background.'padding:15px;margin:25px}'
            .'.td_l{background-image:url('.$images.'/email_head_l.png);min-width:391px;height:75px;margin:0;float:left;background-repeat:no-repeat}'
            .'.td_r{background-image:url('.$images.'/email_head_r.png);background-repeat:no-repeat;width:16px;height:75px;float:right}'
            .'.td_c{background-image:url('.$images.'/email_head_c.png);background-repeat:repeat-x;float:left}'
            .'.titulo{margin-left:130px;margin-top:25px;font-family:Verdana;font-size:16px;font-weight:bold;color:rgb(165,29,33)}'
            .'.limpar{clear:both}</style>';
    }

    private const AIR = '<p>Please find attached our AIR Shipping Instructions.</p>'
        .'<p><strong><u><span style="background-color: yellow">VERY IMPORTANT:</span></u></strong></p>'
        .'<ul><li>Please provide us in advance copies of HAWB &amp; MAWB before issuing the originals. The originals cannot be issued without our final checking. Cargo cannot catch the airplane without the docs are okay.</li></ul>'
        .'<ul><li>You have to put the labels in all side of the boxes (four and top). The package must contain labels (tags) with MAWB and HAWB numbers, cargo details, shipper and Cnee information, invoice number at all sides of the package - this is mandatory.</li></ul>'
        .'<ul><li>Original HAWB must show ORIGINAL 2 FOR CONSIGNEE and rated. Is mandatory to have freight stated at the documents otherwise cargo will be stuck at customs until documents are fixed which takes time (meaning warehouse fees) and penalties.</li>'
        .'<li>MAWB must show: CONSOLIDATION AS PER ATTACHED CARGO MANIFEST</li></ul>'
        .'<ul><li>Docs must be signed by hand. Both HAWB/ MAWB must show same executed on/ flight date.</li></ul>'
        .'<ul><li><strong><span style="background-color: lime">Original docs (MAWB, HAWB, manifest, invoice and packing list) must be sent with cargo. Brazilian Customs NOT accept E-AWB or digital copies to release the cargo.</span></strong></li></ul>'
        .'<p>You must confirm the kind of package with Shipper and put the correct information at AWB’s:</p>'
        .'<p style="margin-left:21pt">·&nbsp; Wooden Packing: Processed Wood<br/>·&nbsp; Wooden Packing: Treated and Certified<br/>·&nbsp; Wooden Packing: Not Treated and not Certified<br/>·&nbsp; Wooden Packing: Not Applicable</p>'
        .'<p>Just for your knowledge, the cargo cannot be on loaded on the airplane without the documents be checked and approved, and original ones attached with cargo. If the cargo arrives in Brazil with wrong or without original documents, cargo will be in hold and a huge fines/ penalty applicable by Brazilian Customs.</p>'
        .'<p>Thank you!</p>';
}
