<?php

namespace App\Actions\Cobranca\Documents;

use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * xlsRecibo (legado): Nota de Débito em .xls (Excel 97, o formato que abria em todas as máquinas do financeiro).
 * Sem recibo é a "Nota de Débito SEM PAGAMENTO"; com recibo/declaração mostra o nº e o total pago.
 */
class DebitNote
{
    private const BRL = '"R$ "#,##0.00';

    private const RATE = '""#,##0.0000';

    public function __construct(private ChargeDetails $details) {}

    /** @param  ?int  $receiptNumber  id do fin_payment_receipt (rid) ou do fin_payment_boleto (bid) */
    public function __invoke(FinCharges $charge, ?int $receiptNumber = null): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $this->fill($sheet, $charge, $receiptNumber);

        return response()->streamDownload(fn () => (new Xls($book))->save('php://output'), 'recibo.xls', [
            'Content-Type' => 'application/vnd.ms-excel',
        ]);
    }

    private function fill(Worksheet $sheet, FinCharges $charge, ?int $receiptNumber): void
    {
        $e = $charge->embarque;
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;
        $date = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';
        $name = fn (string $table, string $key, $id) => $id ? (string) DB::table($table)->where($key, $id)->value('nome') : '';
        $port = function ($id) {
            $p = $id ? DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')
                ->where('p.porto_id', $id)->first(['p.nome', 'p.uncode', 'c.uncode as pais']) : null;

            return $p ? "{$p->nome} ({$p->pais})({$p->uncode})" : '';
        };
        $h1 = ['font' => ['bold' => true, 'size' => 12], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]];
        $left = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]];

        $logo = new Drawing;
        $logo->setName('Logo');
        $logo->setPath(resource_path('images/logo_recibo.png'));
        $logo->setCoordinates('C1');
        $logo->setWorksheet($sheet);

        $sheet->getColumnDimension('A')->setWidth(35);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setAutoSize(true);
        $sheet->getColumnDimension('E')->setAutoSize(true);

        $sheet->getStyle('A2')->applyFromArray($h1);
        $sheet->setCellValue('A2', 'NOTA DE DÉBITO NRO '.str_replace('IMP', '', (string) $e->booking));
        $sheet->setCellValue('A3', 'DMF LOGISTICS DO BRASIL LTDA');
        $sheet->setCellValue('A4', 'CNPJ: 07.052.088/0001-34');
        $sheet->setCellValue('A5', 'INSCR. ESTADUAL: ISENTO');
        $sheet->mergeCells('A7:B7');
        $sheet->getStyle('A7')->applyFromArray([...$h1, 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
        $sheet->setCellValue('A7', 'Informações Financeiras');

        $x = 8;
        $row = function (string $label, mixed $value = null, ?string $mergeTo = null) use ($sheet, &$x) {
            $sheet->setCellValue('A'.++$x, $label);

            if ($value !== null) {
                $sheet->setCellValueExplicit('B'.$x, (string) $value, 's');
            }

            if ($mergeTo) {
                $sheet->mergeCells("B{$x}:{$mergeTo}{$x}");
            }
        };

        $row('Data', $date($charge->payment_date));
        $row('BL', $bl ? $bl->bl : $e->house_bl, 'D');
        $row('Booking', $e->booking);
        $row('Fatura', $bl?->dmf_ref ?: $e->invoice);

        if ($receiptNumber) {
            $row('Recibo', 'DMF'.($charge->payment_date?->format('Y') ?? '1970').$receiptNumber);
        }

        $row('Navio', $name('navio', 'navio_id', $e->impor_expor === 'e' ? $e->navio_id : $e->navio_id_destino), 'D');
        $row('Saída', $date($e->saida_efetiva));

        if ($e->impor_expor !== 'e') {
            $row('Free Time Demurrage', $e->free_time);
        }

        $row('Origem', $port($e->porto_origem_id), 'D');
        $row('Porto de Destino', $port($e->porto_destino_id), 'D');
        $row('Containers');
        $row('Exportador', $charge->nome_exportador ?: (filled($bl?->shipper) ? $bl->shipper : $name('pessoa', 'pessoa_id', $e->pessoa_id_cliente)), 'E');
        $row('Importador', $charge->nome_importador ?: (filled($bl?->consignee) ? $bl->consignee : $name('pessoa', 'pessoa_id', $e->pessoa_id_importador)), 'E');
        $row('');

        if ($bl) {
            $sheet->fromArray(['Equipamento', 'Número', 'Quantidade'], null, 'A'.++$x);

            foreach (DB::table('container as c')->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')
                ->where('c.bl_id', $bl->bl_id)->get(['c.*', 't.nome as tipo']) as $c) {
                $sheet->fromArray(['1 - '.$c->tipo, $c->numero, number_format($this->details->containerFactor($c), 4, ',', '.')], null, 'A'.++$x);
                $sheet->getStyle("B{$x}")->applyFromArray($left);
            }
        } else {
            $sheet->fromArray(['Equipamento', 'Quantidade'], null, 'A'.++$x);

            foreach (DB::table('mercadoria as m')->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
                ->where('m.embarque_id', $e->embarque_id)->get(['t.nome as tipo', 'm.quantidade']) as $m) {
                $sheet->fromArray([$m->tipo, $m->quantidade], null, 'A'.++$x);
            }
        }

        $row('');
        $sheet->fromArray(['Despesa', 'Taxa (moeda)', 'Valor Unitário', 'Índice', 'Valor do Item'], null, 'A'.++$x);

        $money = function (int $x, float $value) use ($sheet, $left) {
            $sheet->setCellValue("E{$x}", $value);
            $sheet->getStyle("E{$x}")->applyFromArray($left)->getNumberFormat()->setFormatCode(self::BRL);
        };

        foreach ($this->details->items($charge) as $item) {
            $raw = $item['raw'];
            $label = $raw['value'] > 0 ? ($raw['service'] ? 'Serviço de ' : 'Reembolso de ').$item['expense'] : $item['expense'];

            $sheet->fromArray([$label, $raw['rate'], $raw['unit'], $raw['index']], null, 'A'.++$x);
            $sheet->getStyle("A{$x}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("B{$x}:D{$x}")->applyFromArray($left);
            $sheet->getStyle("B{$x}")->getNumberFormat()->setFormatCode(self::RATE);
            $sheet->getStyle("D{$x}")->getNumberFormat()->setFormatCode(self::RATE);
            $sheet->getStyle("C{$x}")->getNumberFormat()->setFormatCode('"'.$raw['symbol'].'"#,##0.00');
            $money($x, $raw['value']);
        }

        if ((float) $charge->valor_iof) {
            $sheet->setCellValue('A'.++$x, 'Despesas Financeiras/IOF');
            $money($x, (float) $charge->valor_iof);
        }

        if ((float) $charge->discount) {
            $sheet->setCellValue('A'.++$x, 'DESCONTO');
            $money($x, (float) $charge->discount);
        }

        $sheet->setCellValue('A'.++$x, 'TOTAL');
        $money($x, $receiptNumber
            ? (float) $charge->received_value + (float) $charge->fine + (float) $charge->interest - (float) $charge->discount
            : (float) $charge->total_value - (float) $charge->discount);

        $agent = $e->pessoa_id_representante ? DB::table('pessoa as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.paises_id')
            ->where('p.pessoa_id', $e->pessoa_id_representante)->first(['p.nome', 'p.endereco', 'p.cnpj', 'c.nome as pais', 'c.uncode']) : null;

        if ($agent) {
            $row('');
            $sheet->setCellValue('A'.++$x, 'On behalf of');
            $sheet->getStyle("A{$x}")->applyFromArray($h1);
            $sheet->setCellValue('A'.++$x, $agent->nome);
            $sheet->mergeCells("A{$x}:C{$x}");
            $sheet->setCellValue('A'.++$x, $agent->endereco);
            $sheet->getStyle("A{$x}")->getAlignment()->setWrapText(true);
            $sheet->setCellValue('A'.++$x, $agent->pais.(filled($agent->uncode) ? " ({$agent->uncode})" : ''));
            $sheet->setCellValue('A'.++$x, "NIF: {$agent->cnpj}");
            $sheet->getStyle("A{$x}")->applyFromArray($h1);
            $sheet->setCellValue('A'.++$x, 'A presente Nota de Débito somente terá validade após a confirmação do crédito em conta corrente, quando será enviado nota definitiva');
        }
    }
}
