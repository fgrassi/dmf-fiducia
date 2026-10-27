<?php

namespace App\Actions\Cobranca\Documents;

use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use TCPDF;

/**
 * Cobranca::geraRecibo (legado), só o desenho: recibo de pagamento com arte de fundo, assinatura e rodapé.
 * Com $itemIds/$totalOverride é o recibo de um grupo de NFS-e; $includeExtras põe IOF, juros e multa neste recibo.
 * Com $boleto é o "Recibo do Boleto" (Cobranca::geraReciboBoleto): sem vencimento, despesas sem "Serviço/Reembolso de",
 * colunas de 21mm, fecho "Valores recebidos através do pagamento de Boleto Bancário." e sem "VÁLIDO SEM ASSINATURA".
 */
class ReceiptPdf
{
    private const FS = 8;

    private const SPACE = 3.7;

    private const LS = 45;

    private const LN = 5;

    private const PAGE_END = 255;

    private const MONTHS = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function __construct(private ChargeDetails $details) {}

    /**
     * @param  ?list<int>  $itemIds  null = todos os itens
     * @return array{0: TCPDF, 1: float} PDF e total do recibo
     */
    public function render(FinCharges $charge, int $receiptId, ?array $itemIds = null, ?float $totalOverride = null, bool $includeExtras = true, bool $boleto = false): array
    {
        $e = $charge->embarque;
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;
        $name = fn (string $table, string $key, $id) => $id ? (string) DB::table($table)->where($key, $id)->value('nome') : '';
        $date = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : now()->format('d/m/Y');
        $port = function ($id) {
            $p = $id ? DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')
                ->where('p.porto_id', $id)->first(['p.nome', 'p.uncode', 'c.uncode as pais']) : null;

            return $p ? "{$p->nome} ({$p->pais})({$p->uncode})" : '';
        };

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Cobrança');
        $pdf->SetSubject('Recibo de Pagamento ');
        $pdf->SetKeywords('Recibo de Pagamento');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setImageScale(4);
        $pdf->AddPage();
        $pdf->setMargins(20, 10);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->Ln(20);
        $pdf->Image(resource_path('images/dmf.jpg'), 0, 0, 200);

        $cell = fn (float $w, string $text, string $align = 'L', int $ln = 0, float $h = self::LN) => $pdf->MultiCell($w, $h, $text, 0, $align, false, $ln, '', '', true);
        $space = fn (int $times = 1) => $pdf->Ln(self::SPACE * $times);
        $field = function (string $label, ?string $value, int $width = 45) use ($pdf, $cell) {
            $pdf->SetFont('Helvetica', 'B', self::FS);
            $cell(self::LS, $label);
            $pdf->SetFont('Helvetica', '', self::FS);
            $cell($width, (string) $value);
        };
        $footer = function (bool $lineBreaks = false) use ($pdf, $space) {
            $pdf->setTextColor(126, 0, 1);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->MultiCell(180, 5, 'Rua Borges de Medeiros, 274 - 2o. Andar - Santa Cruz do Sul - RS - Brasil - CEP 96810-156', 0, 'C', false, 0, '', 265, true);
            $lineBreaks ? $pdf->Ln() : $space();
            $pdf->MultiCell(180, 5, 'PABX: +55 51 3717-8200 - Fax: +55 51 3717-8224', 0, 'C', false, 0, '', '', true);
            $lineBreaks ? $pdf->Ln() : $space();
            $pdf->MultiCell(180, 5, 'www.dmflog.com.br - dmf@dmflog.com.br', 0, 'C', false, 0, '', '', true);
        };
        $header = function (array $columns, bool $lineBreak = false) use ($pdf, $cell, $space) {
            $pdf->SetFont('Helvetica', 'B', self::FS);

            foreach ($columns as [$w, $title]) {
                $cell($w, $title, 'C');
            }

            $lineBreak ? $pdf->Ln() : $space();
        };
        $newPage = function (?array $columns = null, bool $lineBreak = false) use ($pdf, $header, $space, $footer) {
            $footer();
            $space(2);
            $pdf->AddPage();
            $pdf->setMargins(20, 10);
            $pdf->Ln(20);
            $pdf->setTextColor(0, 0, 0);

            if ($columns) {
                $header($columns, $lineBreak);
            }
        };

        $pdf->SetFont('Helvetica', 'BI', 12);
        $cell(165, (string) $receiptId, 'R', 1);
        $cell(165, 'RECIBO DE PAGAMENTO ', 'C', 1);
        $space();
        $pdf->SetFont('Helvetica', 'B', 10);

        foreach (['DMF LOGISTICS DO BRASIL LTDA', 'CNPJ: 07.052.088/0001-34', 'INSCR. ESTADUAL: ISENTO'] as $line) {
            $cell(100, $line);
            $space();
        }

        $space();
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Informações Financeiras', 'C');
        $space(2);

        $field('Data:', now()->format('d/m/Y'));
        $space();
        if (! $boleto) {
            $field('Vencimento:', $date($charge->due_date));
            $space();
        }

        $field('BL:', $bl ? $bl->bl : $e->house_bl, self::LS);
        $space();
        $field('Booking:', $e->booking);
        $space();
        $field('Fatura:', $bl?->dmf_ref ?: $e->invoice, 120);
        $space();
        $field('Recibo No.:', 'DMF'.now()->format('Y').$receiptId, self::LS);
        $space();

        if ($e->impor_expor === 'e') {
            $field('Navio:', $name('navio', 'navio_id', $e->navio_id), self::LS);
            $pdf->Ln();
            $field('Saída:', $date($e->saida_efetiva));
        } else {
            $field('Navio:', $name('navio', 'navio_id', $e->navio_id_destino), self::LS);
            $pdf->Ln();
            $field('Chegada:', $date($e->chegada_efetiva));
        }

        $pdf->Ln();
        $field('Free Time Demurrage (dias):', (string) $e->free_time);
        $pdf->Ln();
        $field('Origem:', $port($e->porto_origem_id), 55);
        $pdf->Ln();
        $field('Porto de Destino:', $port($e->porto_destino_id), 55);
        $pdf->Ln();

        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LS, 'Exportador:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell($boleto ? 150 : 140, $charge->nome_exportador ?: (filled($bl?->shipper) ? $bl->shipper : $name('pessoa', 'pessoa_id', $e->pessoa_id_cliente)), 'L', 1);
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LS, 'Importador:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell($boleto ? 150 : 140, $charge->nome_importador ?: (filled($bl?->consignee) ? $bl->consignee : $name('pessoa', 'pessoa_id', $e->pessoa_id_importador)));

        // legado avança 3×3,7mm e "Containers" cobre importador de várias linhas; aqui desce a altura da célula
        $pdf->Ln();
        $space();
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Containers');
        $space(2);

        $containerHeader = $bl ? [[80, 'Equipamento'], [40, 'Número'], [45, 'Quantidade']] : [[80, 'Equipamento'], [85, 'Quantidade']];
        $header($containerHeader);
        $rows = $bl
            ? DB::table('container as c')->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')
                ->where('c.bl_id', $bl->bl_id)->get(['c.*', 't.nome as tipo'])->values()
                ->map(fn ($c, $n) => [($n + 1).' - '.$c->tipo, (string) $c->numero, number_format($this->details->containerFactor($c), 4, ',', '.')])
            : DB::table('mercadoria as m')->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
                ->where('m.embarque_id', $e->embarque_id)->get(['t.nome as tipo', 'm.quantidade'])
                ->map(fn ($m) => [(string) $m->tipo, (string) $m->quantidade]);

        foreach ($rows as $row) {
            if ($pdf->GetY() > self::PAGE_END) {
                $newPage($containerHeader);
            }

            $pdf->setTextColor(0, 0, 0);
            $pdf->SetFont('Helvetica', '', self::FS);

            foreach ($row as $n => $value) {
                $cell($containerHeader[$n][0], $value, 'C');
            }

            $space();
        }

        if ($pdf->GetY() + 15 > self::PAGE_END) {
            $newPage();
        }

        $space(2);
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Despesas');
        $pdf->Ln();

        // legado: no recibo o cabeçalho da 1ª página tem larguras próprias; nas quebras usa as das linhas
        $itemHeader = $boleto
            ? [[81, 'Despesa'], [21, 'Taxa (moeda)'], [21, 'Valor Unitário'], [21, 'Índice'], [21, 'Valor do Item']]
            : [[81, 'Despesa'], [21, 'Taxa (moeda)'], [24, 'Valor Unitário'], [15, 'Índice'], [24, 'Valor do Item']];
        $header($boleto ? $itemHeader : [[81, 'Despesa'], [21, 'Taxa (moeda)'], [26, 'Valor Unitário'], [11, 'Índice'], [26, 'Valor do Item']]);
        $brl = fn (float $v) => number_format($v, 2, ',', '.');
        $items = collect($this->details->items($charge))
            ->when($itemIds, fn ($c) => $c->whereIn('id', $itemIds))
            ->sortBy(fn ($i) => $i['raw']['name'], SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($items as $item) {
            $raw = $item['raw'];
            $text = $raw['value'] > 0 && ! $boleto ? ($raw['service'] ? 'Serviço de ' : 'Reembolso de ').$item['expense'] : $item['expense'];
            $pdf->setTextColor(0, 0, 0);
            $pdf->SetFont('Helvetica', '', self::FS);
            $h = $this->height($pdf, 81, $text);

            $cell(81, $text, 'L', 0, $h);
            $cell(21, number_format($raw['rate'], 4, ',', '.'), 'C', 0, $h);
            $cell($itemHeader[2][0], trim($raw['symbol'].' '.$brl($raw['unit'])), 'R', 0, $h);
            $cell($itemHeader[3][0], number_format($raw['index'], 4, ',', '.'), 'R', 0, $h);
            $cell($itemHeader[4][0], 'R$ '.$brl($raw['value']), 'R', 0, $h);
            $pdf->Ln();

            if ($pdf->GetY() > self::PAGE_END) {
                $newPage($itemHeader, lineBreak: true);
            }
        }

        if ($includeExtras && (float) $charge->valor_iof) {
            $cell(144, 'Despesas Financeiras/IOF');
            $cell(21, 'R$ '.$brl((float) $charge->valor_iof), 'R');
            $pdf->Ln();
        }

        if ($pdf->GetY() + 15 > self::PAGE_END) {
            $newPage($itemHeader, lineBreak: true);
        }

        $extras = [
            ['Juros', $includeExtras ? (float) $charge->interest : 0.0, 'R$ '],
            ['Multa', $includeExtras ? (float) $charge->fine : 0.0, 'R$ '],
            ['Desconto', $totalOverride === null ? (float) $charge->discount : 0.0, 'R$ - '],
        ];

        foreach ($extras as [$label, $value, $prefix]) {
            if ($value > 0) {
                $pdf->SetFont('Helvetica', '', self::FS);
                $cell(42, $label);
                $cell(123, $prefix.$brl($value), 'R');
                $pdf->Ln();
            }
        }

        // grupos de NFS-e já trazem o IOF no total; juros e multa entram no recibo que leva os extras
        $total = round($totalOverride !== null
            ? $totalOverride + ($includeExtras ? (float) $charge->fine + (float) $charge->interest : 0.0)
            : (float) $charge->received_value + (float) $charge->fine + (float) $charge->interest - (float) $charge->discount, 2);

        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(124, 'Total');
        $cell(41, 'R$ '.$brl($total), 'R');
        $pdf->Ln();

        // fecho (observação, banco, assinatura) ocupa ~70mm e o rodapé é fixo em 265mm; o legado quebrava com 45 e sobrepunha
        if ($pdf->GetY() + 70 > 265) {
            $newPage();
            $pdf->Ln();
        }

        $bank = $charge->ref_bank ? DB::table('cmn_banks')->where('id', $charge->ref_bank)->first() : null;

        $pdf->SetFont('Helvetica', '', 10);
        $cell(165, (string) $charge->discount_obs);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 10);
        if ($boleto) {
            $cell(165, 'Valores recebidos através do pagamento de Boleto Bancário.');
        } else {
            $cell(165, 'Vencimento da cobrança em '.($charge->due_date?->format('d/m/Y') ?? '').'.');
            $pdf->Ln();
            $cell(165, 'Valores depositados em nossa conta corrente do banco '.($bank && filled($bank->name) ? "{$bank->name} ({$bank->bank_number})" : '')
                .', em '.$date($charge->payment_date).'.');
        }

        $pdf->Ln(self::LN * 2);
        $pdf->SetFont('Helvetica', '', 10);
        $cell(165, 'Santa Cruz do Sul, '.now()->format('d').' de '.self::MONTHS[now()->month].' de '.now()->format('Y'));
        $pdf->Ln();
        $pdf->Image(resource_path('images/marcia.jpg'), 135, '', 50);
        $pdf->Ln(self::LN * 3);
        $pdf->Ln(2);
        $cell(157, '_____________________', 'R');
        $pdf->Ln(self::LN * 2);
        $cell(150, 'Márcia Ziebell', 'R');
        $pdf->Ln(self::LN * 2);

        if (! $boleto) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->setTextColor(126, 0, 1);
            $cell(150, 'VÁLIDO SEM ASSINATURA', 'R');
            $pdf->Ln();
        }

        $footer(lineBreaks: true);

        return [$pdf, $total];
    }

    /** DMFUtil::getAlturaConteudo: altura que o texto ocupa numa célula de largura $w. */
    private function height(TCPDF $pdf, float $w, string $text): float
    {
        return ceil($pdf->getStringHeight($w, $text));
    }
}
