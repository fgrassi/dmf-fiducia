<?php

namespace App\Actions\ControlePa;

use App\Models\Embarque;
use App\Services\Cobranca\ChargeDetails;
use Illuminate\Support\Facades\DB;
use stdClass;
use TCPDF;

/**
 * freightInvoice (legado): PDF A4 da Freight Invoice da DMF USA para o pagador da reserva de exportação. Reproduz o layout
 * do legado em milímetros: cabeçalho, partes, descrição das despesas de cobrança "f" (PA) com o total e a conta de pagamento.
 */
class FreightInvoicePdf
{
    private const UNIT_CONTAINER = 2;

    /** Embarque::getDadosBancariosRecebedor (legado), pela letra do recebedor. A quebra de linha final é do legado e dá a altura do bloco no PDF. */
    public const RECEIVERS = [
        'd' => "DMF LOGISTICS USA LLC\nINTERNATIONAL FINANCE BANK\n777 SW 37TH AVE SUITE 700\nMIAMI, FL 33135\nABA# 067011692\nACC# 1200062493\nPNC SWIFT CODE: IFBKUS3M\n        ",
        'a' => "AMERICAN INTL. CARGO SERVICE INC\nPNC BANK NA\n2 TOWER CENTER BLVD\nEAST BRUNSWICK, NJ 08816\nABA# 031207607\nACC# 8026218901\nPNC SWIFT CODE: PNCCUS33\n        ",
        'j' => "JADE INTERNATIONAL, INC\nPNC BANK\n501 CHESTER PIKE\nPROSPECT PARK, PA 19076\nABA# 031000053\nACC# 8624393291\nPNC SWIFT CODE: PNCCUS33\n        ",
    ];

    public function __construct(private ChargeDetails $details) {}

    public function render(Embarque $booking): TCPDF
    {
        $date = $booking->saida_efetiva ? date('d/m/Y', strtotime((string) $booking->saida_efetiva)) : '';
        $goods = DB::table('mercadoria as m')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->where('m.embarque_id', $booking->getKey())
            ->orderBy('m.mercadoria_id')
            ->get(['m.quantidade', 't.nome as equipment'])
            ->map(fn (stdClass $m) => "{$m->quantidade} X {$m->equipment}");
        $payer = $this->person($booking->pessoa_id_pagador) ?? $this->person($booking->pessoa_id_importador);
        [$lines, $total, $currency] = $this->lines($booking);
        $bank = self::RECEIVERS[$booking->recebedor_freight_invoice] ?? '';

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Billing Instructions');
        $pdf->SetSubject('Billing Instructions: ');
        $pdf->SetKeywords('DMF Billing Instructions');
        $pdf->setPrintHeader(false);
        $pdf->setHeaderMargin(15);
        $pdf->setFooterMargin(23);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->setImageScale(4);
        $pdf->AddPage();
        $pdf->setMargins(7, 7, 7, 7);

        $cell = fn (float $w, string $text, string $border = '', string $align = 'L', bool $fill = false, int $ln = 0, float|string $x = '', float|string $y = '', ?float $h = null) => $pdf->MultiCell($w, $h ?? 0, $text, $border, $align, $fill, $ln, $x, $y, true);
        $font = fn (string $style, int $size) => $pdf->SetFont('Helvetica', $style, $size);

        $font('', 10);
        $cell(0, '', 'LTRB', 'C', false, 1, 10, '', 20);
        $cell(0, 'DMF LOGISTICS USA LLC', 'LR', 'C', false, 1, 10, 17);
        $cell(0, 'TAX ID: 87-2953651', 'LR', 'C', false, 1, 10, 21);
        $pdf->SetFillColor(211, 211, 211);
        $pdf->Ln(10);
        $font('B', 10);
        $cell(30, 'Date:', '', 'L', false, 0, 10);
        $font('', 10);
        $cell(30, $date);
        $font('B', 12);
        $cell(63, 'FREIGHT INVOICE', 'LTR', 'L', false, 0, 140);
        $pdf->Ln();

        $font('B', 10);
        $cell(30, 'BL:', '', 'L', false, 0, 10);
        $font('', 10);
        $cell(60, (string) ($booking->bl_direto ? $booking->master_bl : $booking->house_bl));
        $font('B', 10);
        $cell(12, 'ETD:');
        $font('', 10);
        $cell(30, $date);
        $font('B', 12);
        $cell(63, 'NBR. '.$booking->booking, 'LBR', 'L', false, 0, 140);
        $pdf->Ln();

        $font('B', 10);
        $cell(30, 'Vessel:', '', 'L', false, 0, 10);
        $font('', 10);
        $cell(60, (string) ($booking->navio_id ? DB::table('navio')->where('navio_id', $booking->navio_id)->value('nome') : ''));
        $font('B', 10);
        $cell(12, 'VG:');
        $font('', 10);
        $cell(60, (string) $booking->vg);
        $pdf->Ln();

        $font('B', 10);
        $cell(30, 'Shipper:', '', 'L', false, 0, 10);
        $font('', 10);
        $cell(0, (string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_cliente)->value('nome'));
        $pdf->Ln();
        $font('B', 10);
        $cell(30, 'Origin:', '', 'L', false, 0, 10);
        $font('', 10);
        $cell(60, $this->port($booking->porto_origem_id));
        $font('B', 10);
        $cell(30, 'Destination:');
        $font('', 10);
        $cell(60, $this->port($booking->porto_destino_id));
        $pdf->Ln();
        $font('B', 10);
        $cell(30, 'Equipments:', '', 'L', false, 0, 10);

        $font('', 10);
        foreach ($goods as $good) {
            $cell(60, $good, '', 'L', false, 0, 40);
            $pdf->Ln(4);
        }
        $pdf->Ln(10);

        $font('B', 11);
        $cell(30, 'TO:', 'T', 'L', false, 0, 10);
        $cell(0, (string) $payer?->nome, 'T');
        $pdf->Ln();

        foreach ([(string) $payer?->endereco, $this->country($payer?->paises_id), 'Tel: '.$payer?->fone1] as $line) {
            $cell(0, $line, '', 'L', false, 0, 40);
            $pdf->Ln();
        }

        $cell(0, '', 'T', 'L', false, 0, 10);
        $pdf->Ln(5);
        $font('B', 10);
        $cell(30, 'Attn:', '', 'L', false, 0, 10);
        $font('', 10);
        $cell(90, 'FINANCIAL DEPARTMENT');
        $pdf->Ln(10);

        $font('B', 10);
        $cell(0, 'DESCRIPTION', '1', 'L', true, 0, 10);
        $pdf->Ln();

        $row = function (string $description, string $value = '', string $totalValue = '', string $borders = 'LR', bool $fill = false, ?float $height = null) use ($cell, $pdf) {
            $cell(113, $description, $borders, 'L', $fill, 0, 10, '', $height);
            $cell(40, $value, $borders, 'R', $fill, 0, '', '', $height);
            $cell(40, $totalValue, $borders, 'R', $fill, 0, '', '', $height);
            $pdf->Ln();
        };

        $font('', 10);
        foreach ($lines as [$description, $value]) {
            $row($description, $value);
        }

        $font('', 10);
        foreach ($goods as $good) {
            $row($good);
        }

        $row('');
        $row('Subject to local changes at destination');
        $row('');
        $font('B', 10);
        $row('Please note that the total value must be paid without any discount.', height: 10);
        $row('');
        $row('TOTAL', trim($currency.' '.number_format($total, 2, ',', '.')), fill: true);
        $row('');
        $cell(113, 'PAYMENT ACCOUNT', 'LRT', 'L', false, 0, 10);
        $cell(40, '', 'LR', 'R');
        $cell(40, '', 'LR', 'R');
        $pdf->Ln();

        // DmfUtil::getStringHeight (legado): mede o texto numa página de teste e arredonda para cima
        $probe = clone $pdf;
        $probe->AddPage();
        $probe->SetY(0);
        $probe->MultiCell(113, 3, $bank);
        $height = ceil($probe->GetY());
        $row($bank, height: $height);

        $cell(113, 'TOTAL', 'LTB', 'L', true, 0, 10);
        $cell(40, '', 'TB', 'R', true);
        $cell(40, trim($currency.' '.number_format($total, 2, ',', '.')), 'TBR', 'R', true);

        return $pdf;
    }

    /**
     * getDespesasFreightInvoice: despesas de cobrança "f". Nas de container o valor é a tarifa vezes a quantidade de
     * containers do equipamento (DmfUtil::numeroContainers). A moeda do total é a da última despesa, como no legado.
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: float, 2: string}
     */
    private function lines(Embarque $booking): array
    {
        $total = 0.0;
        $currency = '';
        $lines = [];

        $expenses = DB::table('fin_expenses_values as v')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->leftJoin('cmn_currencies as cs', 'cs.id', '=', 'v.ref_currency_sr')
            ->where('v.ref_embarque', $booking->getKey())
            ->where('v.billing', 'f')
            ->orderBy('v.id')
            ->get(['v.*', 'v.ref_bl as v_bl', 'e.name as expense_name', 'e.ref_unity', 'u.acronym as unity_name', 'cs.symbol as sr_symbol']);

        foreach ($expenses as $d) {
            $value = (float) $d->selling_rate;

            if ((int) $d->ref_unity === self::UNIT_CONTAINER) {
                $value *= (float) DB::table('mercadoria')->where('embarque_id', $booking->getKey())->where('tipo_container_id', $d->ref_equipment)->sum('quantidade');
            }

            $total += $value;
            $currency = (string) $d->sr_symbol;
            $lines[] = [$this->details->expenseLabel($d), trim($currency.' '.number_format($value, 2, ',', '.'))];
        }

        return [$lines, $total, $currency];
    }

    private function person(?int $id): ?stdClass
    {
        return $id ? DB::table('pessoa')->where('pessoa_id', $id)->first(['nome', 'endereco', 'fone1', 'paises_id']) : null;
    }

    /** Paises::__toString: nome (uncode). */
    private function country(?int $id): string
    {
        $country = $id ? DB::table('paises')->where('paises_id', $id)->first(['nome', 'uncode']) : null;

        return $country ? $country->nome.(filled($country->uncode) ? " ({$country->uncode})" : '') : '';
    }

    /** Embarque::getPortoOrigem: nome (país). */
    private function port(?int $id): string
    {
        $port = $id ? DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')->where('p.porto_id', $id)->first(['p.nome', 'c.nome as country']) : null;

        return $port ? "{$port->nome} ({$port->country})" : '';
    }
}
