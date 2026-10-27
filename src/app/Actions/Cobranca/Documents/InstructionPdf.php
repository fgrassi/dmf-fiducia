<?php

namespace App\Actions\Cobranca\Documents;

use App\Actions\Cobranca\Listing\ChargeDueDate;
use App\Actions\Cobranca\Nfse\EmissionGroups;
use App\Actions\Cobranca\Nfse\InstructionEmailSections;
use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\CmnFile;
use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use TCPDF;

/**
 * Cobranca::geraPdf (legado): PDF da Instrução de Cobrança (dados da reserva, containers, despesas, avisos,
 * dados bancários e contatos), gravado em uploads/cobranca como ic{modal}{id}{ddmmyyGi} e anexado à IC;
 * com $sendEmail, vai ao cliente no e-mail "Instrução de Cobrança" (cliente em exceção: só o financeiro).
 *
 * A IC de seguro (geraPdfSeguro) é o InsurancePdf. ponytail: título em Helvetica (o legado usa BebasNeue).
 */
class InstructionPdf
{
    private const FS = 7;

    private const LH = 10;

    private const LN = 7;

    private const RED = [165, 29, 33];

    /** Clientes que sempre veem os dados bancários, mesmo com boleto (Tabacum). */
    private const ALWAYS_BANK_DATA = [887, 5588];

    private const ZIM_LINES = 14;

    public function __construct(
        private ChargeDetails $details,
        private ChargeDueDate $dueDate,
        private ChargeRecipients $recipients,
    ) {}

    /** @return string arquivo em uploads/cobranca */
    public function __invoke(FinCharges $charge, bool $sendEmail = true, bool $recalculated = false): string
    {
        $e = $charge->embarque;
        $name = 'ic'.$e->impor_expor.$charge->id.now()->format('dmyGi');

        Storage::disk('tracking_uploads')->put("cobranca/{$name}.pdf", $this->render($charge)->Output('', 'S'));

        $file = CmnFile::forceCreate(['name' => $name, 'subject' => "Cobrança do Booking {$e->booking}", 'created_at' => now()->toDateString(), 'uid' => 1]);
        $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();
        DB::table('fin_charges_files')->insert(['ref_charge' => $charge->id, 'ref_file' => $file->getKey()]);

        if ($sendEmail) {
            $this->send($charge, "{$name}.pdf", $recalculated);
        }

        return "{$name}.pdf";
    }

    public function render(FinCharges $charge): TCPDF
    {
        $e = $charge->embarque;
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;
        $import = $e->impor_expor === 'i';
        $date = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';
        $person = fn ($id) => $id ? (string) DB::table('pessoa')->where('pessoa_id', $id)->value('nome') : '';
        $ship = fn ($id) => $id ? (string) DB::table('navio')->where('navio_id', $id)->value('nome') : '';
        $port = function ($id) {
            $p = $id ? DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')->where('p.porto_id', $id)->first(['p.nome', 'c.nome as pais']) : null;

            return $p ? "{$p->nome} ({$p->pais})" : '';
        };

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Cobrança');
        $pdf->SetSubject('Documento de Cobrança ');
        $pdf->SetKeywords('DMF Documento de Cobrança');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetFooterMargin(10);
        $pdf->setImageScale(4);

        $cell = fn (float $w, $h, string $text, $border = 0, string $align = 'L', $x = '', $y = '', bool $fill = false) => $pdf->MultiCell($w, $h, $text, $border, $align, $fill, 0, $x, $y, true);
        $red = fn () => $pdf->SetTextColor(...self::RED);
        $page = function () use ($pdf, $charge) {
            $pdf->AddPage();
            $pdf->setMargins(20, 10);
            $pdf->SetAutoPageBreak(true, 7);
            $pdf->Image(resource_path('images/logo_head_1.png'), 40, 15, 30);
            $pdf->SetFont('Helvetica', 'B', 16);
            $pdf->SetTextColor(...self::RED);
            $pdf->MultiCell(0, 5, 'INSTRUÇÃO DE ', 0, 'L', false, 1, 75, 20);
            $pdf->MultiCell(0, 5, 'COBRANÇA', 0, 'L', false, 1, 105, 20);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->MultiCell(190, 5, 'IC'.str_pad((string) $charge->id, 4, '0', STR_PAD_LEFT).'/'.$charge->created_at->format('Y'), 0, 'R', false, 1, 10, 10);
        };
        $continuation = function () use ($pdf, $page) {
            $page();
            $pdf->SetFont('Helvetica', '', self::FS);
            $pdf->Ln(20);
        };

        $page();
        $due = $this->dueDate->display($charge);
        $pdf->MultiCell(190, 5, 'Data de Vencimento: '.(is_string($due) ? $due : $due->format('d/m/Y')), 0, 'R', false, 1, 10, 15);

        // cabeçalhos dos campos (em vermelho) e, depois, os valores por cima
        $pdf->SetDrawColor(...self::RED);
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $pdf->MultiCell(190, 5, '', 'B', 'L', false, 1, 10, 30, true);
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(95, self::LH, 'Data', 'BR', 'L', 10, 35);
        $cell(95, self::LH, 'Reserva', 'B', 'L', 105, 35);
        $pdf->Ln();
        $cell(95, self::LH, 'Fatura', 'BR', 'L', 10);
        $cell(95, self::LH, 'HBL', 'B', 'L', 105);
        $pdf->Ln();

        if ($e->tipo_embarque === 'd') {
            $cell(95, self::LH, 'RE', 'BR', 'L', 10);
            $cell(95, self::LH, 'DDE', 'B', 'L', 105);
            $pdf->Ln();
        }

        $cell(47.5, self::LH, 'ETD', 'BR', 'L', 10);
        $cell(47.5, self::LH, 'ETA', 'BR', 'L', 57.5);
        $cell(47.5, self::LH, $import ? 'Free Time Demurrage (dias)' : 'Free Time Detention (dias)', 'BR', 'L', 105);
        $cell(47.5, self::LH, 'Navio', 'B', 'L', 152.5);
        $pdf->Ln();
        $cell(95, self::LH, 'Porto de Origem', 'BR', 'L', 10);
        $cell(95, self::LH, 'Porto de Destino', 'B', 'L', 105);
        $pdf->Ln();
        $cell(95, self::LH, 'Exportador', 'R', 'L', 10);
        $cell(95, self::LH, 'Importador', '', 'L', 105);
        $pdf->Ln();
        $pdf->SetTextColor(0);

        $y = 40;
        $cell(95, self::LN, $date($charge->updated_at ?: $charge->created_at), 0, 'L', 15, $y);
        $cell(95, self::LN, (string) $e->booking, 0, 'L', 110, $y);
        $y += 10;
        $cell(95, self::LN, (string) ($bl?->dmf_ref ?: $e->invoice), 0, 'L', 15, $y);
        $cell(95, self::LN, (string) $bl?->bl, 0, 'L', 110, $y);

        if ($e->tipo_embarque === 'd') {
            $y += 10;
            $cell(95, self::LH, (string) $e->re, 'BR', 'L', 10, $y);
            $cell(95, self::LH, (string) $e->dde, 'B', 'L', 105, $y);
            $pdf->Ln();
        }

        $y += 10;
        $cell(47.5, self::LN, $date($e->saida_efetiva), 0, 'L', 15, $y);
        $cell(47.5, self::LN, $date($e->chegada_efetiva ?: $e->chegada_prevista), 0, 'L', 62.5, $y);
        $cell(47.5, self::LN, (string) ($import ? $e->free_time : $e->free_time_detention), 0, 'L', 110, $y);
        $cell(47.5, self::LN, $import ? $ship($e->navio_id_destino) : $ship($e->navio_id), 0, 'L', 157.5, $y);
        $y += 10;
        $cell(95, self::LN, $port($e->porto_origem_id), 0, 'L', 15, $y);
        $cell(95, self::LN, $port($e->porto_destino_id), 0, 'L', 110, $y);
        $y += 10;

        $exporter = match (true) {
            filled($charge->nome_exportador) => (string) $charge->nome_exportador,
            $charge->ref_bl && filled($bl?->shipper) => (string) $bl->shipper,
            default => $person($e->pessoa_id_cliente),
        };
        // Condor SA (4646) com importador CVF Logistica (12406): a cobrança sai no nome do Notify
        $importer = match (true) {
            filled($charge->nome_importador) => (string) $charge->nome_importador,
            (int) $e->cliente_id === 4646 && (int) $e->pessoa_id_importador === 12406 => $person($e->pessoa_id_notify),
            $charge->ref_bl && filled($bl?->consignee) => (string) $bl->consignee,
            default => $person($e->pessoa_id_importador),
        };
        $h = max($this->height($pdf, 95, $exporter), $this->height($pdf, 95, $importer));
        $cell(95, $h, $exporter, '', 'L', 15, $y);
        $cell(95, $h, $importer, '', 'L', 110, $y);
        $pdf->Ln();

        $containersHeader = function () use ($pdf, $cell, $red) {
            $red();
            $cell(190, 5, 'Containers', 'TB', 'C', 10);
            $pdf->Ln();
            $cell(95, self::LN, 'Equipamento', 'BR', 'C', 10);
            $cell(55, self::LN, 'Número', 'BR', 'C', 105);
            $cell(40, self::LN, 'Quantidade', 'B', 'C', 160);
            $pdf->Ln();
            $pdf->SetTextColor(0);
        };
        $containersHeader();

        foreach ($this->details->containers($charge) as $c) {
            $cell(95, 0, (string) $c['type'], 'BR', 'C', 10);
            $cell(55, 0, (string) $c['number'], 'BR', 'C', 105);
            $cell(40, 0, $c['factor'], 'B', 'C', 160);
            $pdf->Ln();

            if ($pdf->GetY() + 4 > 280) {
                $continuation();
                $containersHeader();
            }
        }

        if ($pdf->GetY() + 24 > 280) {
            $continuation();
        }

        $pdf->Ln();
        $pdf->Ln();
        $expensesHeader = function () use ($pdf, $cell, $red) {
            $red();
            $cell(190, 5, 'Despesas', 'TB', 'C', 10);
            $pdf->Ln();
            $cell(70, self::LN, 'Despesa', 'BR', 'C', 10);

            foreach (['Taxa (moeda)', 'Valor Unitário', 'Índice'] as $title) {
                $cell(30, self::LN, $title, 'BR', 'C');
            }

            $cell(30, self::LN, 'Valor do Item', 'B', 'C');
            $pdf->Ln();
            $pdf->SetTextColor(0);
        };
        $expensesHeader();
        $partial = false;

        foreach ($this->details->items($charge) as $item) {
            $raw = $item['raw'];
            $text = $raw['value'] > 0 ? ($raw['service'] ? 'Serviço de ' : 'Reembolso de ').' '.$item['expense'] : $item['expense'];
            $unit = $raw['unit'];

            // índice parcial: valor unitário proporcional, marcado com **
            if ($raw['index'] < 1) {
                $unit *= $raw['index'];
                $partial = true;
                $text .= '**';
            }

            $h = $this->height($pdf, 70, $text);
            $cell(70, $h, $text, 'BR', 'L', 10);
            $cell(30, $h, number_format($raw['rate'], 4, ',', '.'), 'BR', 'C');
            $cell(30, $h, $raw['symbol'].' '.number_format($unit, 2, ',', '.'), 'BR', 'R');
            $cell(30, $h, number_format($raw['index'], 8, ',', '.'), 'BR', 'R');
            $cell(30, $h, 'R$ '.number_format($raw['value'], 2, ',', '.'), 'B', 'R');
            $pdf->Ln();

            if ($pdf->GetY() + $h > 280) {
                $continuation();
                $expensesHeader();
            }
        }

        if ((float) $charge->valor_iof > 0) {
            $cell(150, self::LN, 'Despesas Financeiras/IOF', 'B', 'L', 10);
            $cell(40, self::LN, 'R$ '.number_format((float) $charge->valor_iof, 2, ',', '.'), 'B', 'R');
            $pdf->Ln();
        }

        $cell(150, self::LN, 'Total', 'B', 'L', 10);
        $cell(40, self::LN, 'R$ '.number_format((float) $charge->total_value - (float) $charge->discount, 2, ',', '.'), 'B', 'R');
        $pdf->Ln();

        if ($partial) {
            $cell(190, self::LN, '** Valor unitário calculado proporcionalmente ao índice', 'B', 'L', 10);
            $pdf->Ln();
        }

        $red();
        $boleto = DB::table('fin_clients_boleto')->where('ref_client', $e->cliente_id)->where($import ? 'boleto_imp' : 'boleto_exp', 1)->exists();

        if ($pdf->GetY() + ($boleto ? 26 : 73) > 290) {
            $continuation();
        }

        $pdf->SetFillColor(225, 225, 225);

        if ($import) {
            $pdf->Ln();
            $cell(190, 0, 'GENTILEZA NOTAR QUE A PARTIR DO DIA 1 DE ABRIL DE 2021 SERÁ COBRADO O VALOR DE R$ 300,00 PARA AS SOLICITAÇÕES DE DESBLOQUEIO CE-IMP APÓS ÀS 15H. SALIENTAMOS QUE O DESBLOQUEIO NÃO SERÁ FEITO SEM QUE HAJA O ACEITE DE VOSSA PARTE COM RELAÇÃO A COBRANÇA.', 1, 'L', 10, '', true);
            $pdf->Ln();
        }

        // com boleto não mostra os dados bancários (salvo Tabacum)
        if (! $boleto || in_array((int) $e->cliente_id, self::ALWAYS_BANK_DATA, true)) {
            $this->bankData($pdf, $cell, $import, (int) $e->armador_id === self::ZIM_LINES);
        }

        $pdf->Ln();
        $this->contacts($pdf, $cell, $e);

        return $pdf;
    }

    private function bankData(TCPDF $pdf, callable $cell, bool $import, bool $zim): void
    {
        if ($import) {
            $cell(190, 0, 'INFORMAMOS QUE PARA A SOLICITAÇÃO DE DESBLOQUEIO DO SISTEMA MERCANTE (RETENÇÃO DE FRETE) SE FAZ NECESSARIA A ENTREGA DE UMA VIA DO BL ORIGINAL JUNTO AO REPRESENTANTE DA DMF NO PORTO DE DESCARGA NO DIA DO PAGAMENTO. ', 'LTR', 'L', 10, '', true);
            $pdf->Ln();
            $cell(190, 0, 'CASO OS DOCUMENTOS PARA A LIBERAÇÃO NÃO SEJAM ENTREGUES NA DATA DO PAGAMENTO, PODERÁ OCASIONAR COBRANÇA DE DIFERENÇA CAMBIAL.', 'LBR', 'L', 10, '', true);
        } elseif ($zim) {
            $pdf->SetFillColor(209, 204, 204);
            $pdf->SetFont('Helvetica', 'B', 7);
            $cell(190, 0, '** IMPORTANTE **', 'LTR', 'C', 10, '', true);
            $pdf->Ln();
            $pdf->SetFont('Helvetica', '', 7);
            $cell(190, 0, 'MULTA ADICIONAL NO VALOR DE R$ 62,00 PARA LIBERAÇÃO / PAGAMENTO EFETUADO COM MAIS DE 5 DIAS E ATÉ 20 DIAS CONTADOS A PARTIR DA DESATRACAÇÃO DO NAVIO.', 'LR', 'L', 10, '', true);
            $pdf->Ln();
            $cell(190, 0, 'MULTA ADICIONAL NO VALOR DE R$ 271,00 PARA LIBERAÇÃO / PAGAMENTO EFETUADO COM MAIS DE 21 DIAS E ATÉ 29 DIAS CONTADOS A PARTIR DA DESATRACAÇÃO DO NAVIO.', 'LBR', 'L', 10, '', true);
            $pdf->Ln();
            $cell(190, 0, 'A PARTIR DE 30 DIAS DA DESATRACAÇÃO A MULTA ADICIONAL SERÁ DE R$ 586,00.', 'LBR', 'L', 10, '', true);
            $pdf->SetFont('Helvetica', '', self::FS);
            $pdf->SetFillColor(225, 225, 225);
        }

        $pdf->Ln(8);
        $cell(190, 5, 'Dados Bancários', 'TB', 'C', 10);
        $pdf->Ln();

        $holder = "CNPJ: 07.052.088/0001-34\nFav: DMF Logistics do Brasil Ltda";
        $cell(47.5, 25, "\nBRADESCO (237)\nAg: 1587\nC/C: 227370-5\n{$holder}", 1, 'L', 10);
        $cell(47.5, 25, "\nSANTANDER (033)\nAg: 3285\nC/C: 13000195-3\n{$holder}", 'BR');
        $cell(47.5, 25, "\nBANCO DO BRASIL (001)\nAg: 0180-5\nC/C: 38681-2\n{$holder}\nPIX CNPJ: 07.052.088/0001-34", 'B');
        $cell(47.5, 25, "\nSICREDI (748)\nAg: 0156\nC/C: 31930-4\n{$holder}\nPIX EMAIL: pix@dmflog.com.br", 1);
    }

    /** Contatos do financeiro (e, na importação marítima com despachante DMF, o contato para os documentos) e rodapé. */
    private function contacts(TCPDF $pdf, callable $cell, object $e): void
    {
        $broker = $e->impor_expor === 'i' && $e->tipo_embarque === 'm' && $e->despachante_dmf_id
            ? DB::table('despachante')->where('despachante_id', $e->despachante_dmf_id)->first()
            : null;
        $width = $broker ? 50 : 95;

        $cell($broker ? 100 : 190, self::LN, 'Contatos Setor Financeiro', 'T', 'C', 10, 250);

        if ($broker) {
            $cell(85, self::LN, 'Contato DMF para Apresentação dos Documentos', 'T', 'C', 115, 250);
        }

        $pdf->Ln();
        $y = $pdf->GetY();
        $pdf->SetFont('Helvetica', '', self::FS);

        foreach (['Sr.a Márcia Ziebell', 'Financial and Administrative Manager', 'marcia@dmflog.com.br', 'Telefone Direto: +55 51 3717-8204 '] as $i => $line) {
            $cell($width, self::LN, $line, 0, 'L', 15, $y + 3 * $i);
        }

        if ($broker) {
            $cell(85, self::LN, mb_strtoupper((string) $broker->nome), 0, 'L', 115, $y);
            $cell(85, self::LN, (string) ($broker->dados_contato ?? ''), 0, 'L', 115, $y + 3);
        }

        $pdf->SetFont('Helvetica', 'B', 6);
        $cell(190, 0, 'Rua Borges de Medeiros, 274 - 2o. Andar - Santa Cruz do Sul - RS - Brasil  - CEP: 96810-034', 'T', 'L', 10, 275);
        $pdf->Ln();
        $cell(95, 0, 'Phone: +55 51 3717-8200 / FAX: +55 51 3717-8224', 0, 'L', 10);
        $pdf->Ln();
        $cell(95, 0, 'www.dmflog.com.br - financeiro@dmflog.com.br', 0, 'L', 10);
    }

    /** E-mail único "Instrução de Cobrança" (instrução + aviso de boleto + Late Payment Fee). */
    private function send(FinCharges $charge, string $file, bool $recalculated): void
    {
        $e = $charge->embarque;
        // FinClientsException só recebe a IC depois do recálculo (salvo armador que não recalcula)
        $inException = ! $recalculated && ! EmissionGroups::carrierDoesNotRecalculate($e)
            && DB::table('fin_clients_exception')->where('ref_client', ChargeRates::clientId($e))->exists();
        $client = DB::table('pessoa')->where('pessoa_id', ChargeRates::clientId($e))->first(['nome', 'razao_social']);
        $type = (int) $charge->ref_type !== 1 ? ' - '.mb_strtoupper((string) $charge->type?->name) : '';
        $subject = "DMF Logistics: Instrução de Cobrança {$type}: {$e->booking} - ".preg_replace("/\r?\n/m", ' ', (string) $e->invoice)
            .' - '.($client ? ($client->razao_social ?: $client->nome) : '')
            .($charge->ref_bl && filled($e->customer_ref) ? ' - '.preg_replace("/\r?\n/m", ' ', (string) $e->customer_ref) : '');

        Mail::send('mail.cobranca.instruction', [
            'sections' => InstructionEmailSections::build($charge, $inException),
        ], function ($mail) use ($charge, $file, $subject, $inException) {
            $mail->from(ChargeRecipients::COLLECTION_EMAIL, 'DMF Logistics do Brasil - Setor de Cobrança')
                ->to([ChargeRecipients::COLLECTION_EMAIL, ...($inException ? [] : array_keys(($this->recipients)($charge)))])
                ->subject($subject)
                ->attach(Storage::disk('tracking_uploads')->path("cobranca/{$file}"), ['as' => $file]);
        });
    }

    /** DMFUtil::getAlturaConteudo: altura que o texto ocupa numa célula de largura $w. */
    private function height(TCPDF $pdf, float $w, string $text): float
    {
        $pdf->SetFont('Helvetica', '', self::FS);

        return ceil($pdf->getStringHeight($w, $text));
    }
}
