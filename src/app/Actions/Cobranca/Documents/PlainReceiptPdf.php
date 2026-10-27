<?php

namespace App\Actions\Cobranca\Documents;

use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * geraReciboPlain (legado): recibo de pagamento em PDF sem a arte de fundo, para imprimir em papel timbrado.
 * Layout copiado do legado (TCPDF, A4, mm); a data e o nº do recibo usam o dia/ano de hoje, como lá.
 */
class PlainReceiptPdf
{
    private const FS = 7;

    private const SPACE = 4;

    private const LS = 45;

    /** y a partir do qual quebra a página */
    private const PAGE_END = 255;

    private const MONTHS = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function __construct(private ChargeDetails $details) {}

    public function __invoke(FinCharges $charge, int $receiptId): StreamedResponse
    {
        $receipt = $charge->receipts()->findOrFail($receiptId);
        $pdf = $this->render($charge, $receipt->getKey());

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), "recibo_dmf{$receipt->getKey()}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render(FinCharges $charge, int $receiptId): TCPDF
    {
        $e = $charge->embarque;
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;
        $name = fn (string $table, string $key, $id) => $id ? (string) DB::table($table)->where($key, $id)->value('nome') : '';
        $date = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';
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

        $cell = fn (float $w, string $text, string $align = 'L', int $ln = 0, float $h = 0) => $pdf->MultiCell($w, $h, $text, 0, $align, false, $ln, '', '', true);
        $field = function (string $label, ?string $value, int $width = 45) use ($pdf, $cell) {
            $pdf->SetFont('Helvetica', 'B', self::FS);
            $cell(self::LS, $label);
            $pdf->SetFont('Helvetica', '', self::FS);
            $cell($width, (string) $value);
        };

        $pdf->SetFont('Helvetica', 'BI', 12);
        $cell(165, (string) $receiptId, 'R', 1);
        $cell(165, 'RECIBO DE PAGAMENTO ', 'C', 1);
        $pdf->Ln(self::SPACE * 3);
        $pdf->SetFont('Helvetica', 'B', 10);

        foreach (['DMF LOGISTICS DO BRASIL LTDA', 'CNPJ: 07.052.088/0001-34', 'INSCR. ESTADUAL: ISENTO'] as $line) {
            $cell(100, $line);
            $pdf->Ln(self::SPACE);
        }

        $pdf->Ln(10);
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Informações Financeiras', 'C');
        $pdf->Ln(self::SPACE * 2);

        $field('Data:', now()->format('d/m/Y'));
        $pdf->Ln(self::SPACE);
        $field('BL:', $bl ? $bl->bl : $e->house_bl, self::LS);
        $pdf->Ln(self::SPACE);
        $field('Booking:', $e->booking);
        $pdf->Ln(self::SPACE);
        $field('Fatura:', $bl?->dmf_ref ?: $e->invoice);
        $pdf->Ln(self::SPACE);
        $field('Recibo No.:', 'DMF'.now()->format('Y').$receiptId, self::LS);
        $pdf->Ln(self::SPACE);

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
        $field('Origem:', $port($e->porto_origem_id));
        $pdf->Ln();
        $field('Porto de Destino:', $port($e->porto_destino_id));
        $pdf->Ln();

        $exporter = $charge->nome_exportador ?: (filled($bl?->shipper) ? $bl->shipper : $name('pessoa', 'pessoa_id', $e->pessoa_id_cliente));
        $importer = $charge->nome_importador ?: (filled($bl?->consignee) ? $bl->consignee : $name('pessoa', 'pessoa_id', $e->pessoa_id_importador));

        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LS, 'Exportador:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(140, $exporter, 'L', 1, $this->height($pdf, 140, $exporter));
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LS, 'Importador:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(140, $importer, 'L', 0, $this->height($pdf, 140, $importer));

        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Containers');
        $pdf->Ln();

        $containerHeader = $bl ? [[80, 'Equipamento'], [40, 'Número'], [45, 'Quantidade']] : [[80, 'Equipamento'], [85, 'Quantidade']];
        $header = function (array $columns) use ($pdf, $cell) {
            $pdf->SetFont('Helvetica', 'B', self::FS);

            foreach ($columns as [$w, $title]) {
                $cell($w, $title, 'C');
            }

            $pdf->Ln();
        };
        $newPage = function (?array $columns = null) use ($pdf, $header) {
            $pdf->AddPage();
            $pdf->setMargins(20, 10);
            $pdf->Ln(20);
            $pdf->setTextColor(0, 0, 0);

            if ($columns) {
                $header($columns);
            }
        };

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

            $pdf->SetFont('Helvetica', '', self::FS);

            foreach ($row as $n => $value) {
                $cell($containerHeader[$n][0], $value, 'C');
            }

            $pdf->Ln();
        }

        if ($pdf->GetY() + 15 > self::PAGE_END) {
            $newPage();
        }

        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Despesas');
        $pdf->Ln();

        $itemHeader = [[81, 'Despesa'], [21, 'Taxa (moeda)'], [24, 'Valor Unitário'], [15, 'Índice'], [24, 'Valor do Item']];
        $header($itemHeader);
        $brl = fn (float $v) => number_format($v, 2, ',', '.');

        foreach (collect($this->details->items($charge))->sortBy(fn ($i) => $i['raw']['name'], SORT_NATURAL | SORT_FLAG_CASE) as $item) {
            $raw = $item['raw'];
            $text = $raw['value'] > 0 ? ($raw['service'] ? 'Serviço de ' : 'Reembolso de ').$item['expense'] : $item['expense'];
            $h = $this->height($pdf, 81, $text);

            $pdf->setTextColor(0, 0, 0);
            $pdf->SetFont('Helvetica', '', self::FS);
            $cell(81, $text, 'L', 0, $h);
            $cell(21, number_format($raw['rate'], 4, ',', '.'), 'C', 0, $h);
            $cell(24, trim($raw['symbol'].' '.$brl($raw['unit'])), 'R', 0, $h);
            $cell(15, number_format($raw['index'], 4, ',', '.'), 'R', 0, $h);
            $cell(24, 'R$ '.$brl($raw['value']), 'R', 0, $h);
            $pdf->Ln();

            if ($pdf->GetY() > self::PAGE_END) {
                $newPage($itemHeader);
            }
        }

        if ((float) $charge->valor_iof) {
            $cell(144, 'Despesas Financeiras/IOF');
            $cell(21, 'R$ '.$brl((float) $charge->valor_iof), 'R');
            $pdf->Ln();
        }

        if ($pdf->GetY() + 15 > self::PAGE_END) {
            $newPage($itemHeader);
        }

        foreach ([['Juros', (float) $charge->interest, 'R$ '], ['Multa', (float) $charge->fine, 'R$ '], ['Desconto', (float) $charge->discount, 'R$ - ']] as [$label, $value, $prefix]) {
            if ($value > 0) {
                $pdf->SetFont('Helvetica', '', self::FS);
                $cell(42, $label);
                $cell(123, $prefix.$brl($value), 'R');
                $pdf->Ln();
            }
        }

        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(124, 'Total');
        $cell(41, 'R$ '.$brl((float) $charge->received_value + (float) $charge->fine + (float) $charge->interest - (float) $charge->discount), 'R');
        $pdf->Ln();

        if ($pdf->GetY() + 45 > 295) {
            $newPage();
            $pdf->Ln();
        }

        $bank = $charge->ref_bank ? DB::table('cmn_banks')->where('id', $charge->ref_bank)->first() : null;

        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(165, (string) $charge->discount_obs);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(165, 'Valores depositados em nossa conta corrente do banco '.($bank && filled($bank->name) ? "{$bank->name} ({$bank->bank_number})" : '')
            .', em '.($charge->payment_date?->format('d/m/Y') ?? now()->format('d/m/Y')).'.');
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(165, 'Santa Cruz do Sul, '.now()->format('d').' de '.self::MONTHS[now()->month].' de '.now()->format('Y'));
        $pdf->Ln();
        $pdf->Ln(2);
        $cell(157, '_____________________', 'R');
        $pdf->Ln();
        $cell(150, 'Márcia Ziebell', 'R');
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $pdf->setTextColor(126, 0, 1);
        $cell(160, 'VÁLIDO SEM ASSINATURA', 'R');
        $pdf->Ln();

        return $pdf;
    }

    /** DMFUtil::getAlturaConteudo: altura que o texto ocupa numa célula de largura $w. */
    private function height(TCPDF $pdf, float $w, string $text): float
    {
        $font = [$pdf->getFontFamily(), $pdf->getFontStyle(), $pdf->getFontSizePt()];
        $pdf->SetFont('Helvetica', '', self::FS);
        $h = ceil($pdf->getStringHeight($w, $text));
        $pdf->SetFont(...$font);

        return $h;
    }
}
