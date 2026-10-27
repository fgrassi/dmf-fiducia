<?php

namespace App\Actions\Cobranca\Listing;

use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * executeExcel + preencherAbaExcelCobrancas (legado): exporta o conjunto filtrado em duas abas (Abertos / Pagos),
 * ignorando o "Exibir", com a linha vencida em vermelho e a cor do semáforo no status.
 */
class ExportCharges
{
    private const HEADERS = [
        'Num. Cobrança', 'Tipo', 'Booking', 'BL', 'Número NF', 'Cliente',
        'Valor Total', 'Data Criação', 'Vencimento', 'Data Baixa', 'Status', 'NFSe',
    ];

    private const AGING_COLORS = ['success' => '92D050', 'warning' => 'FFC000', 'danger' => 'FF6B6B'];

    public function __construct(private ChargeDueDate $dueDate) {}

    /** @param  array<string, mixed>  $filters  dados do filtro "busca" da listagem */
    public function __invoke(array $filters): StreamedResponse
    {
        $query = FinCharges::query()
            ->with(['type', 'bl', 'embarque.cliente', 'latestNfse'])
            ->withSum('items as items_total', 'item_value')
            ->orderByDesc('id');

        $rows = app(BuildChargeListQuery::class)
            ->applyFilters($query, $filters)
            ->get()
            ->partition(fn (FinCharges $r) => $r->status === ChargeStatus::Open);

        $book = new Spreadsheet;
        $book->getProperties()
            ->setCreator('DMF Logistics do Brasil')
            ->setTitle('Central de Cobranças');
        $this->fill($book->getActiveSheet(), 'Abertos', $rows[0]);
        $this->fill($book->createSheet(), 'Pagos', $rows[1]);
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'cobrancas_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  Collection<int, FinCharges>  $rows */
    private function fill(Worksheet $sheet, string $title, Collection $rows): void
    {
        $sheet->setTitle($title);
        $sheet->fromArray(self::HEADERS);
        $last = 'L';
        $this->style($sheet, "A1:{$last}1", '474B4E', '474B4E')
            ->getFont()
            ->setBold(true)
            ->getColor()
            ->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$last}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');

        $today = CarbonImmutable::today();
        $line = 2;

        foreach ($rows as $row) {
            $due = $this->dueDate->display($row);
            $overdue = $row->status === ChargeStatus::Open && ($final = $this->dueDate->final($row)) && $final->lessThan($today);
            $nfse = $row->latestNfse?->status ?? 'nao_gerada';

            $sheet->fromArray([
                $row->id,
                $row->type?->name,
                $row->embarque?->booking,
                $row->bl?->bl ?? $row->embarque?->house_bl,
                $row->num_nota_fiscal,
                $row->embarque?->cliente?->nome,
                round((float) $row->items_total + (float) $row->valor_iof, 2),
                $row->created_at ? Date::PHPToExcel($row->created_at) : null,
                $due instanceof CarbonImmutable ? Date::PHPToExcel($due) : $due,
                $row->payment_date ? Date::PHPToExcel($row->payment_date) : null,
                $row->status->label(),
                BuildChargeListQuery::NFSE_OPTIONS[$nfse === 'erro_http' ? 'erro' : $nfse] ?? ucfirst($nfse),
            ], null, "A{$line}", true);

            // fundo da linha: vencida > zebra
            $this->style($sheet, "A{$line}:{$last}{$line}", 'D0D0D0', $overdue ? 'F8D7DA' : ($line % 2 === 0 ? 'F5F5F5' : null));
            $sheet->getStyle("G{$line}")->getNumberFormat()->setFormatCode('[$R$-416] #,##0.00');
            $sheet->getStyle("G{$line}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("H{$line}:J{$line}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $sheet->getStyle("H{$line}:K{$line}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->fillColor($sheet, "K{$line}", self::AGING_COLORS[$row->agingColor()]);

            if ($overdue) {
                $sheet->getStyle("I{$line}")->getFont()->setBold(true)->getColor()->setRGB('C53D36');
                $this->fillColor($sheet, "I{$line}", 'F5C6CB');
            }

            $line++;
        }

        foreach (range('A', $last) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /** Fonte Arial 12, borda fina e fundo opcional (excelAplicarEstiloCelula/Cabecalho). */
    private function style(Worksheet $sheet, string $range, string $border, ?string $background): Style
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setName('Arial')->setSize(12)->getColor()->setRGB('47433A');
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($border);

        if ($background) {
            $this->fillColor($sheet, $range, $background);
        }

        return $style;
    }

    private function fillColor(Worksheet $sheet, string $range, string $rgb): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }
}
