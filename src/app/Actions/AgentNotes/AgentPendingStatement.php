<?php

namespace App\Actions\AgentNotes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

/**
 * resumoNotasAgentes/xls (legado), o "dmf_notes_report": extrato em Excel das notas em aberto de um agente, em duas listas
 * (devido ao agente, com o valor negativo, e devido à DMF) e os totais.
 *
 * Tipo "t" = todas as notas do agente. Os agentes AIC (1838, 3548, 10960, 10959, 10961) têm outra regra: o relatório é por tipo
 * de reserva, "m" (marítimo, com as notas variadas) ou "a" (aéreo); os créditos vão para "devido ao agente" e os débitos para
 * "devido à DMF"; 1838 e 3548 saem juntos. Os totais somam todas as moedas, e a moeda da célula é a última encontrada.
 */
class AgentPendingStatement
{
    public const AIC_AGENTS = [1838, 3548, 10960, 10959, 10961];

    private const RED = 'a51d21';

    private const NOT_COURIER = "trim(n.shipper) not in ('COURIER', 'COURRIER')";

    private const COURIER = "trim(n.shipper) in ('COURIER', 'COURRIER')";

    private const USD = '"$"#,##0.00_-';

    private const EUR = '[$EUR ]#,##0.00_-';

    public static function isAic(int $agentId): bool
    {
        return in_array($agentId, self::AIC_AGENTS, true);
    }

    /** Nome do arquivo no mesmo molde do legado (dmf_notes_report_<4 letras>_<ddmmaa>_<Hi>.xls). */
    public function fileName(): string
    {
        return 'dmf_notes_report_'.strtolower(Str::random(4)).'_'.now()->format('dmy_Hi').'.xls';
    }

    public function __invoke(int $agentId, string $type): string
    {
        $isAic = self::isAic($agentId);
        $ids = $isAic ? (in_array($agentId, [1838, 3548], true) ? [1838, 3548] : [$agentId]) : [$agentId];
        $type = $isAic ? ($type === 'a' ? 'a' : 'm') : 't';

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $logo = resource_path('images/logo_head_1.png');

        if (is_file($logo)) {
            $drawing = new Drawing;
            $drawing->setName('Logo')->setDescription('DMF Logo')->setPath($logo)->setCoordinates('A1')->setWorksheet($sheet);
        }

        $agent = (string) DB::table('pessoa')->where('pessoa_id', $agentId)->value('nome');

        $this->fill(
            $sheet,
            $agent,
            $this->notes($ids, $type, $isAic, 'agent'),
            $this->notes($ids, $type, $isAic, 'dmf'),
        );

        return $this->render($book);
    }

    private function render(Spreadsheet $book): string
    {
        ob_start();
        (new Xls($book))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Notas em aberto do agente de um dos lados.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function notes(array $ids, string $type, bool $isAic, string $side): Collection
    {
        $credit = fn (string $mode, string $value) => $this->shipmentNotes('fin_credit_notes', 'c', $value, $ids, $mode, $isAic ? $type : null);
        $debit = fn (string $mode) => $this->shipmentNotes('fin_debit_notes', 'd', AgentNoteLists::DEBIT_VALUE, $ids, $mode, $isAic ? $type : null);
        $varied = fn (int $noteType, ?string $mode, string $shipperRule) => $this->variedNotes($ids, $noteType, $mode, $shipperRule);
        $withVaried = ! $isAic || $type === 'm';

        if ($side === 'agent') {
            $queries = $isAic
                ? [$credit('e', AgentNoteLists::EXPORT_CREDIT_VALUE), $credit('i', AgentNoteLists::EXPORT_CREDIT_VALUE)]
                : [$credit('e', AgentNoteLists::EXPORT_CREDIT_VALUE), $debit('i')];

            if ($withVaried) {
                array_push($queries, $varied(1, 'e', self::NOT_COURIER), $varied(2, 'i', self::NOT_COURIER));
            }
        } else {
            $queries = $isAic
                ? [$debit('i'), $debit('e')]
                : [$credit('i', 'coalesce(n.due_to_dmf, 0) + '.AgentNoteLists::EXPORT_CREDIT_VALUE), $debit('e')];

            if ($withVaried) {
                array_push($queries, $varied(1, 'i', self::NOT_COURIER), $varied(2, 'e', self::NOT_COURIER), $varied(0, null, self::COURIER));
            }
        }

        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->union($query);
        }

        return $union->get();
    }

    /** @param  list<int>  $ids */
    private function shipmentNotes(string $table, string $label, string $value, array $ids, string $mode, ?string $shipmentType): Builder
    {
        return DB::table("{$table} as n")
            ->join('cmn_currencies as cc', 'n.moeda_id', '=', 'cc.id')
            ->join('embarque as e', 'n.ref_embarque', '=', 'e.embarque_id')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->join('pessoa as pe', 'pe.pessoa_id', '=', 'n.ref_agente')
            ->whereIn('n.ref_agente', $ids)
            ->where(fn (Builder $query) => $query->where('n.status_pagamento', 0)->orWhereNull('n.status_pagamento'))
            ->where('e.impor_expor', $mode)
            ->when($shipmentType, fn (Builder $query) => $query->where('e.tipo_embarque', $shipmentType))
            ->selectRaw("n.id, n.name, e.booking, e.house_bl, p.nome as shipper, ({$value}) as valor, cc.symbol, '{$label}' as tipo, n.created_at");
    }

    /**
     * Nota variada; $noteType 0 e $mode null = sem filtro (as de courier entram de qualquer tipo).
     *
     * @param  list<int>  $ids
     */
    private function variedNotes(array $ids, int $noteType, ?string $mode, string $shipperRule): Builder
    {
        return DB::table('fin_despesas_variadas as n')
            ->join('cmn_currencies as cc', 'n.cmn_currencies_id', '=', 'cc.id')
            ->join('pessoa as pe', 'pe.pessoa_id', '=', 'n.agente_id')
            ->whereIn('n.agente_id', $ids)
            ->where(fn (Builder $query) => $query->where('n.status_pagamento', 0)->orWhereNull('n.status_pagamento'))
            ->whereRaw($shipperRule)
            ->when($noteType, fn (Builder $query) => $query->where('n.tipo_nota', $noteType))
            ->when($mode, fn (Builder $query) => $query->where('n.impor_expor', $mode))
            ->selectRaw('n.fin_despesas_variadas_id as id, n.invoice as name, n.booking, n.hbl as house_bl, n.shipper, n.valor, cc.symbol, n.tipo_nota as tipo, n.data_despesa as created_at');
    }

    /**
     * @param  Collection<int, object>  $dueToAgent
     * @param  Collection<int, object>  $dueToDmf
     */
    private function fill(Worksheet $sheet, string $agent, Collection $dueToAgent, Collection $dueToDmf): void
    {
        $first = 8;
        $head = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::RED]]],
            'font' => ['bold' => true, 'color' => ['rgb' => self::RED], 'name' => 'Verdana'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $center = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];

        foreach (['A', 'B', 'C', 'D', 'F'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->mergeCells('A1:B'.($first - 1));
        $sheet->setCellValue('C1', 'Agent: '.$agent);
        $sheet->mergeCells('C1:F'.($first - 1));
        $sheet->getStyle('C1:F'.($first - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::RED], 'size' => 15, 'name' => 'Verdana']]);

        foreach (['Date', 'Note', 'Booking', 'HBL', 'Value', 'Shipper'] as $index => $title) {
            $sheet->setCellValue([$index + 1, $first], $title);
        }

        $sheet->getStyle("A{$first}:F{$first}")->applyFromArray($head);

        $row = $first;
        $symbols = ['agent' => [], 'dmf' => []];
        $sums = ['agent' => 0.0, 'dmf' => 0.0];

        foreach (['agent' => [$dueToAgent, -1], 'dmf' => [$dueToDmf, 1]] as $side => [$notes, $sign]) {
            foreach ($notes as $note) {
                $row++;
                $value = (float) $note->valor * $sign;
                $sheet->fromArray([
                    $note->created_at ? Carbon::parse($note->created_at)->format('d/m/Y') : null,
                    $note->name,
                    $note->booking,
                    $note->house_bl,
                    $value,
                    $note->shipper,
                ], null, "A{$row}");
                $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode($note->symbol === '€' ? self::EUR : self::USD);
                $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($center + [
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::RED]]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => $row % 2 === 0 ? 'FFFFFF' : 'EFEFEF']],
                ]);
                $symbols[$side][$note->symbol] = true;
                $sums[$side] += $value;
            }
        }

        $symbolFormat = fn (array $symbols) => array_key_last($symbols) === '€' ? self::EUR : self::USD;
        $totals = [
            ['TOTAL DUE TO '.strtoupper($agent), $sums['agent'], $symbols['agent']],
            ['TOTAL DUE TO DMF ', $sums['dmf'], $symbols['dmf']],
            ['TOTAL BALANCE', -$sums['agent'] - $sums['dmf'], $symbols['agent'] + $symbols['dmf']],
        ];
        $line = $row + 1;

        foreach ($totals as [$label, $value, $symbols]) {
            $line++;
            $sheet->setCellValue("A{$line}", $label);
            $sheet->mergeCells("A{$line}:D{$line}");
            $sheet->getStyle("A{$line}:D{$line}")->applyFromArray($head);

            if ($symbols === [] && $label === 'TOTAL BALANCE') {
                continue;
            }

            $sheet->setCellValue("E{$line}", $value);
            $sheet->getStyle("E{$line}")->applyFromArray($center + ['font' => ['bold' => true]]);

            if ($symbols !== []) {
                $sheet->getStyle("E{$line}")->getNumberFormat()->setFormatCode($symbolFormat($symbols));
            }
        }

        $line += 2;
        $sheet->setCellValue("A{$line}", 'Revised on '.now()->format('d/m/Y'));
        $sheet->getStyle("A{$line}")->applyFromArray($center);
    }
}
