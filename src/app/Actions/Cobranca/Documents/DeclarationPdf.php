<?php

namespace App\Actions\Cobranca\Documents;

use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * Declaração da baixa por boleto em PDF (legado):
 * - final (geraDeclaracao): "Demonstrativo de Baixa do Boleto Bancário", com arte de fundo, vencimento, assinatura e rodapé;
 * - sem fundo (geraDeclaracaoPlain): "Recibo de Pagamento por Boleto Bancário", para imprimir em papel timbrado.
 * Mesmo esqueleto do PlainReceiptPdf, com os espaçamentos, colunas e textos próprios da declaração no legado.
 */
class DeclarationPdf
{
    private const FS = 7;

    private const SPACE = 4;

    private const LS = 45;

    private const PAGE_END = 255;

    private const MONTHS = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function __construct(private ChargeDetails $details) {}

    /** Download da versão sem fundo. */
    public function __invoke(FinCharges $charge): StreamedResponse
    {
        $pdf = $this->render($charge, plain: true);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'declaracao.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render(FinCharges $charge, bool $plain): TCPDF
    {
        $e = $charge->embarque;
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;
        $name = fn (string $table, string $key, $id) => $id ? (string) DB::table($table)->where($key, $id)->value('nome') : '';
        $date = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';
        $port = fn ($id) => $this->details->port($id);

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Cobrança');
        $pdf->SetSubject('Recibo de Pagamento por Boleto Bancário  ');
        $pdf->SetKeywords('Recibo de Pagamento por Boleto Bancário ');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setImageScale(4);
        $pdf->AddPage();
        $pdf->setMargins(20, 10);
        $pdf->SetAutoPageBreak(true, 7);
        $pdf->Ln(20);

        if (! $plain) {
            $pdf->Image(resource_path('images/dmf.jpg'), 0, 0, 200, 0, 'JPG');
        }

        $cell = fn (float $w, string $text, string $align = 'L', int $ln = 0, float $h = 0) => $pdf->MultiCell($w, $h, $text, 0, $align, false, $ln, '', '', true);
        $space = fn (int $times = 1) => $pdf->Ln(self::SPACE * $times);
        $field = function (string $label, ?string $value, int $width = 45) use ($pdf, $cell) {
            $pdf->SetFont('Helvetica', 'B', self::FS);
            $cell(self::LS, $label);
            $pdf->SetFont('Helvetica', '', self::FS);
            $cell($width, (string) $value);
        };

        $pdf->SetFont('Helvetica', 'BI', 12);
        $cell(165, $plain ? 'Recibo de Pagamento por Boleto Bancário' : 'Demonstrativo de Baixa do Boleto Bancário', 'C', 1);
        $space(3);
        $pdf->SetFont('Helvetica', 'B', 10);

        foreach (['DMF LOGISTICS DO BRASIL LTDA', 'CNPJ: 07.052.088/0001-34', 'INSCR. ESTADUAL: ISENTO'] as $line) {
            $cell(100, $line);
            $space();
        }

        $pdf->Ln(10);
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Informações Financeiras', 'C');
        $space(2);

        $field('Data:', now()->format('d/m/Y'));
        $space();

        if (! $plain) {
            $field('Vencimento:', $date($charge->due_date));
            $space();
        }

        $field('BL:', $bl ? $bl->bl : $e->house_bl, self::LS);
        $space();
        $field('Booking:', $e->booking);
        $space();
        $field('Fatura:', $bl?->dmf_ref ?: $e->invoice, 120);
        $space();

        if ($e->impor_expor === 'e') {
            $field('Navio:', $name('navio', 'navio_id', $e->navio_id), self::LS);
            $pdf->Ln();
            $field('Saída:', $date($e->saida_efetiva));
            $pdf->Ln();
        } elseif ($e->impor_expor === 'i') {
            $field('Navio:', $name('navio', 'navio_id', $e->navio_id_destino), self::LS);
            $pdf->Ln();
            $field('Chegada:', $date($e->chegada_efetiva));
            $pdf->Ln();
        }

        $field('Free Time Demurrage (dias):', (string) $e->free_time);
        $pdf->Ln();
        $field('Origem:', $port($e->porto_origem_id));
        $pdf->Ln();
        $field('Porto de Destino:', $port($e->porto_destino_id));
        $pdf->Ln();

        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LS, 'Exportador:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(140, $charge->nome_exportador ?: (filled($bl?->shipper) ? $bl->shipper : $name('pessoa', 'pessoa_id', $e->pessoa_id_cliente)), 'L', 1);
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LS, 'Importador:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(140, $charge->nome_importador ?: (filled($bl?->consignee) ? $bl->consignee : $name('pessoa', 'pessoa_id', $e->pessoa_id_importador)));

        // legado avança só 2×4mm e o título "Containers" cobre importador de várias linhas; aqui desce a altura da célula
        $pdf->Ln();
        $space();
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Containers');
        $space(2);

        $header = function (array $columns) use ($pdf, $cell, $space) {
            $pdf->SetFont('Helvetica', 'B', self::FS);

            foreach ($columns as [$w, $title]) {
                $cell($w, $title, 'C');
            }

            $space();
        };
        // rodapé com o endereço da DMF: na versão final, ao fim de cada página
        $footer = function () use ($pdf, $space, $plain) {
            if ($plain) {
                return;
            }

            $pdf->setTextColor(126, 0, 1);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->MultiCell(180, 5, 'Rua Borges de Medeiros, 274 - 2o. Andar - Santa Cruz do Sul - RS - Brasil - CEP 96810-156', 0, 'C', false, 0, '', 265, true);
            $space();
            $pdf->MultiCell(180, 5, 'PABX: +55 51 3717-8200 - Fax: +55 51 3717-8224', 0, 'C', false, 0, '', '', true);
            $space();
            $pdf->MultiCell(180, 5, 'www.dmflog.com.br - dmf@dmflog.com.br', 0, 'C', false, 0, '', '', true);
        };
        $newPage = function (?array $columns = null, bool $gap = true) use ($pdf, $header, $space, $footer) {
            $footer();
            $pdf->AddPage();
            $pdf->setMargins(20, 10);
            $pdf->Ln(20);
            $pdf->setTextColor(0, 0, 0);

            if ($gap) {
                $space();
            }

            if ($columns) {
                $header($columns);
            }
        };

        $containerHeader = [[80, 'Equipamento'], [85, 'Quantidade']];
        $header($containerHeader);
        $rows = $bl
            ? DB::table('container as c')->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')
                ->where('c.bl_id', $bl->bl_id)->get(['c.*', 't.nome as tipo'])
                ->map(fn ($c) => [(string) $c->tipo, number_format($this->details->containerFactor($c), 4, ',', '.')])
            : DB::table('mercadoria as m')->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
                ->where('m.embarque_id', $e->embarque_id)->get(['t.nome as tipo', 'm.quantidade'])
                ->map(fn ($m) => [(string) $m->tipo, (string) $m->quantidade]);

        foreach ($rows as [$type, $quantity]) {
            if ($pdf->GetY() > self::PAGE_END) {
                $newPage($containerHeader);
            }

            $pdf->SetFont('Helvetica', '', self::FS);
            $cell(80, $type, 'C');
            $cell(85, $quantity, 'C');
            $space();
        }

        if ($pdf->GetY() + 20 > self::PAGE_END) {
            $newPage(gap: false);
        }

        $space(2);
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Despesas');
        $space(2);

        $itemHeader = [[81, 'Despesa'], [21, 'Taxa (moeda)'], [21, 'Valor Unitário'], [21, 'Índice'], [21, 'Valor do Item']];
        $header($itemHeader);
        $brl = fn (float $v) => number_format($v, 2, ',', '.');

        foreach ($this->details->items($charge) as $item) {
            $raw = $item['raw'];
            $text = $raw['value'] > 0 ? ($raw['service'] ? 'Serviço de ' : 'Reembolso de ').$item['expense'] : $item['expense'];
            $h = $this->height($pdf, 81, $text);

            $pdf->SetFont('Helvetica', '', self::FS);
            $cell(81, $text, 'L', 0, $h);
            $cell(21, number_format($raw['rate'], 4, ',', '.'), 'C', 0, $h);
            $cell(21, trim($raw['symbol'].' '.$brl($raw['unit'])), 'R', 0, $h);
            $cell(21, number_format($raw['index'], 4, ',', '.'), 'R', 0, $h);
            $cell(21, 'R$ '.$brl($raw['value']), 'R', 0, $h);
            $space();

            if ($pdf->GetY() > self::PAGE_END) {
                $newPage($itemHeader);
            }
        }

        if ((float) $charge->valor_iof) {
            $cell(144, 'Despesas Financeiras/IOF');
            $cell(21, 'R$ '.$brl((float) $charge->valor_iof), 'R');
            $space();
        }

        if ($pdf->GetY() + 20 > self::PAGE_END) {
            $newPage($itemHeader);
        }

        $extras = [
            ['Juros', (float) $charge->interest, 'R$ '],
            ['Multa', (float) $charge->fine, 'R$ '],
            ['Taxa de emissão do boleto', (float) $charge->tax_boleto, 'R$ '],
            ['Desconto', (float) $charge->discount, 'R$ - '],
        ];

        foreach ($extras as [$label, $value, $prefix]) {
            if ($value > 0) {
                $pdf->SetFont('Helvetica', '', self::FS);
                $cell(42, $label);
                $cell(123, $prefix.$brl($value), 'R');
                $space();
            }
        }

        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(124, 'Total');
        $cell(41, 'R$ '.$brl((float) $charge->received_value + (float) $charge->fine + (float) $charge->interest
            - (float) $charge->discount + (float) $charge->tax_boleto), 'R');

        if ($pdf->GetY() + ($plain ? 60 : 65) > self::PAGE_END) {
            $newPage();
        }

        $space(2);
        $pdf->SetFont('Helvetica', '', 10);
        $cell(165, (string) $charge->discount_obs);
        $space(2);
        $pdf->SetFont('Helvetica', 'B', 10);
        $cell(165, ($plain ? 'Valores pagos via Boleto Bancário, com' : 'Boleto Bancário com').' vencimento em '.$date($charge->payment_confirmation).'.');
        $space(3);
        $pdf->SetFont('Helvetica', '', 10);
        $cell(165, 'Santa Cruz do Sul, '.now()->format('d').' de '.self::MONTHS[now()->month].' de '.now()->format('Y'));
        $space(2);

        if (! $plain) {
            $pdf->Image(resource_path('images/marcia.jpg'), 135, '', 50);
        }

        $space(3);
        $pdf->Ln(2);
        $cell(157, '_____________________', 'R');
        $space(2);
        $cell(150, 'Márcia Ziebell', 'R');
        $space(2);

        if (! $plain) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->setTextColor(126, 0, 1);
            $cell(150, 'VÁLIDO SEM ASSINATURA', 'R');
            $space();
            $footer();
        }

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
