<?php

namespace App\Actions\Financeiro;

use Carbon\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * relatorioStatementConta/excel (legado): planilha com as abas Info, Statement, Invoices e Payments (textos em inglês, como no legado).
 */
class ExportClientAccountStatement
{
    private const CURRENCY = '[$R$-416] #,##0.00';

    private const NOTES = [
        'All amounts are in BRL. Client is the commercial client of the shipment (embarque.cliente_id), same scope as the internal AR list.',
        'Invoice Total is the gross billed amount (line items + IOF). Received is the finance keyed figure. Manual settlement with no received amount is treated as 100% received (net invoice total); if Payment Date is empty, Due Date is used.',
        'We do not operate customer credit notes (notas de credito). There is no Credit Notes listing. Agent-to-agent credit notes are outside customer AR and are not listed.',
        'Cancelled / voided invoices are physically deleted and are not retained, so no "Cancelled" rows are listed.',
        'This system has no unapplied cash, payment-on-account or suspense-account concept: every allocation is tied to one invoice. There are no Unallocated or Journalled-to-suspense rows.',
        'The Statement tab lists every open item (status Aberto) with a balance, of any age, up to the end date; it ignores the start date. Settled invoices (bank / boleto / manual) are Fully Matched and do not appear on Statement.',
        'Payments tab lists allocations tied to invoices. Amount Applied equals Invoice Total; if the receipt amount is empty, Invoice Total is used. Allocation Status is Fully Allocated, Partially Allocated or Receipted.',
        'Bank receipts have no own date; Payment Date is best-effort from the linked proof-of-payment file, else the invoice payment date, and may be blank.',
    ];

    /** @param  array<string, mixed>  $report  saída de ClientAccountStatement */
    public function __invoke(array $report): StreamedResponse
    {
        $name = 'Statement_of_Account_'.(trim(preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($report['cliente_nome'])), '_') ?: 'cliente').'_'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($this->build($report)))->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array<string, mixed>  $report */
    public function build(array $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('DMF Logistics do Brasil')->setTitle('Statement of Account - '.$report['cliente_nome']);

        $this->info($book->getActiveSheet(), $report);

        $this->table($book->createSheet(), 'Statement', [
            'Invoice Ref', 'NF-e Number', 'Issue Date', 'Due Date', 'Booking Ref', 'BL Ref',
            'Invoice Total', 'Received', 'Balance Open', 'Overpayment', 'Status', 'Days Overdue',
        ], array_map(fn (array $r) => [
            $r['id'], $r['num_nota_fiscal'], $this->date($r['created_at']), $this->date($r['due_date']), $r['booking_ref'], $r['bl_ref'],
            $r['total_value'], $r['received_value'], $r['balance_open'], $r['overpayment'], $r['match_status'], $r['days_overdue'],
        ], $report['statement']), [7, 8, 9, 10]);

        $this->table($book->createSheet(), 'Invoices', [
            'Invoice Ref', 'NF-e Number', 'Issue Date', 'Due Date', 'Booking Ref', 'BL Ref',
            'Invoice Total', 'Received', 'Balance', 'Match Status', 'Payment Date',
        ], array_map(fn (array $r) => [
            $r['id'], $r['num_nota_fiscal'], $this->date($r['created_at']), $this->date($r['due_date']), $r['booking_ref'], $r['bl_ref'],
            $r['total_value'], $r['received_value'], $r['balance'], $r['match_status'], $this->date($r['payment_date']),
        ], $report['invoices']), [7, 8, 9]);

        $this->table($book->createSheet(), 'Payments', [
            'Payment Ref', 'Applied To Invoice Ref', 'NF-e Number', 'Amount Applied', 'Invoice Total',
            'Invoice Issue Date', 'Payment Date', 'Invoice Payment Date', 'Allocation Status',
        ], array_map(fn (array $r) => [
            $r['payment_ref'], $r['invoice_ref'], $r['num_nota_fiscal'], $r['amount_applied'], $r['total_value'],
            $this->date($r['invoice_issue_date']), $this->date($r['payment_date']), $this->date($r['invoice_payment_date']), $r['allocation_status'],
        ], $report['payments']), [4, 5], [4]);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @param  array<string, mixed>  $report */
    private function info(Worksheet $sheet, array $report): void
    {
        $totals = $report['totais'];
        $lines = [
            ['Statement of Account / Accounts Receivable history', ''],
            ['Client', $report['cliente_nome']],
            ['Client tax id (CNPJ)', $report['cliente_cnpj']],
            ['Issue date range', "{$report['data_ini']} to {$report['data_fim']}"],
            ['Generated at', now()->format('Y-m-d H:i:s')],
            ['', ''],
            ['Invoices in period', $totals['invoices']],
            ['Payments in period', $totals['payments']],
            ['Total invoiced', $totals['faturado']],
            ['Total received', $totals['recebido']],
            ['Total open on statement', $totals['em_aberto']],
            ['', ''],
            ['Notes', ''],
            ...array_map(fn (string $note, int $i) => [(string) ($i + 1), $note], self::NOTES, array_keys(self::NOTES)),
        ];

        $sheet->setTitle('Info');
        $sheet->fromArray($lines, null, 'A1');
        $sheet->getStyle('B9:B11')->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getStyle('B9:B11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A13')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(110);
    }

    /**
     * Cabeçalho em negrito, uma linha por registro e a linha TOTAL (somando as colunas de valor) quando há linhas.
     *
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @param  list<int>  $moneyColumns  colunas (1 = A) formatadas como moeda
     * @param  list<int>|null  $totalColumns  colunas somadas na linha TOTAL (por padrão, as de moeda)
     */
    private function table(Worksheet $sheet, string $title, array $headers, array $rows, array $moneyColumns, ?array $totalColumns = null): void
    {
        $sheet->setTitle($title);
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle([1, 1, count($headers), 1])->getFont()->setBold(true);
        $sheet->fromArray($rows, null, 'A2');

        if ($rows !== []) {
            $total = count($rows) + 2;
            $sheet->setCellValue([1, $total], 'TOTAL');

            foreach ($totalColumns ?? $moneyColumns as $column) {
                $sheet->setCellValue([$column, $total], round(array_sum(array_column($rows, $column - 1)), 2));
            }

            $sheet->getStyle([1, $total, count($headers), $total])->getFont()->setBold(true);

            foreach ($moneyColumns as $column) {
                $sheet->getStyle([$column, 2, $column, $total])->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle([$column, 2, $column, $total])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
    }

    private function date(?string $date): string
    {
        return filled($date) ? Carbon::parse($date)->format('d/m/Y') : '';
    }
}
