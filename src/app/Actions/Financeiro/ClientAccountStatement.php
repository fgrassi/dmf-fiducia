<?php

namespace App\Actions\Financeiro;

use App\Models\Pessoa;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * relatorioStatementConta (legado), "Extrato de Conta - Cliente": Statement of Account do cliente comercial da reserva
 * (`embarque.cliente_id`), em BRL, a partir das cobranças (`fin_charges`).
 *
 * - `statement`: cobranças em aberto (status 0) com saldo > 0,01 até a data final, de qualquer idade (ignora a data inicial);
 * - `invoices`: cobranças emitidas no período;
 * - `payments`: alocações das cobranças do período (recibos, baixa por boleto sem recibo, baixa digitada sem recibo nem boleto).
 *
 * Baixa manual (status 3) sem valor recebido conta como 100% recebida (total líquido) e, sem data de pagamento, usa o vencimento.
 *
 * @phpstan-type Input array{cliente_id?: int|string|null, data_ini?: ?string, data_fim?: ?string}
 */
class ClientAccountStatement
{
    /** Cobrança quitada: baixa bancária (1), por boleto (2) ou manual (3). */
    private const SETTLED = [1, 2, 3];

    /**
     * @param  Input  $input
     * @return array{cliente_id: int, cliente_nome: string, cliente_cnpj: string, data_ini: string, data_fim: string, statement: list<array<string, mixed>>, invoices: list<array<string, mixed>>, payments: list<array<string, mixed>>, totais: array<string, float|int>}
     *
     * @throws DomainException cliente ou data inicial em branco (mesma mensagem do legado)
     */
    public function __invoke(array $input): array
    {
        $clientId = (int) ($input['cliente_id'] ?? 0);
        $start = filled($input['data_ini'] ?? null) ? Carbon::parse($input['data_ini'])->toDateString() : null;

        if ($clientId === 0 || $start === null) {
            throw new DomainException('Informe o cliente e a data inicial.');
        }

        $end = filled($input['data_fim'] ?? null) ? Carbon::parse($input['data_fim'])->toDateString() : today()->toDateString();
        $client = Pessoa::query()->find($clientId);

        $statement = $this->statement($clientId, $end);
        $invoices = $this->invoices($clientId, $start, $end);
        $payments = $this->payments($clientId, $start, $end);

        return [
            'cliente_id' => $clientId,
            'cliente_nome' => $client?->nome ?? "#{$clientId}",
            'cliente_cnpj' => (string) ($client?->cnpj ?? ''),
            'data_ini' => $start,
            'data_fim' => $end,
            'statement' => $statement,
            'invoices' => $invoices,
            'payments' => $payments,
            'totais' => [
                'invoices' => count($invoices),
                'payments' => count($payments),
                'faturado' => $this->sum($invoices, 'total_value'),
                'recebido' => $this->sum($invoices, 'received_value'),
                'em_aberto' => $this->sum($statement, 'balance_open'),
                'pago_a_maior' => $this->sum($statement, 'overpayment'),
            ],
        ];
    }

    /** Cobranças em aberto até a data final (qualquer idade). */
    private function statement(int $clientId, string $end): array
    {
        return $this->charges($clientId)
            ->where('fc.status', 0)
            ->whereRaw('DATE(fc.created_at) <= ?', [$end])
            ->whereRaw('(COALESCE(fc.total_value, 0) - COALESCE(fc.discount, 0) - COALESCE(fc.received_value, 0)) > 0.01')
            ->get()
            ->map(fn ($row) => $this->decorate((array) $row))
            ->all();
    }

    /** Cobranças emitidas no período. */
    private function invoices(int $clientId, string $start, string $end): array
    {
        return $this->charges($clientId)
            ->whereRaw('DATE(fc.created_at) BETWEEN ? AND ?', [$start, $end])
            ->get()
            ->map(fn ($row) => $this->decorate((array) $row))
            ->all();
    }

    private function charges(int $clientId): Builder
    {
        return DB::table('fin_charges as fc')
            ->join('embarque as e', 'e.embarque_id', '=', 'fc.ref_booking')
            ->leftJoin('bl as b', 'b.bl_id', '=', 'fc.ref_bl')
            ->where('e.cliente_id', $clientId)
            ->orderBy('fc.created_at')
            ->orderBy('fc.id')
            ->selectRaw('fc.id, fc.num_nota_fiscal, fc.created_at, fc.due_date, fc.payment_date, fc.status, COALESCE(fc.total_value, 0) as total_value, COALESCE(fc.received_value, 0) as received_value, COALESCE(fc.discount, 0) as discount, e.booking as booking_ref, b.mbl_number as bl_ref');
    }

    /** @return list<array<string, mixed>> */
    private function payments(int $clientId, string $start, string $end): array
    {
        $rows = [...$this->receipts($clientId, $start, $end), ...$this->boletos($clientId, $start, $end), ...$this->settlements($clientId, $start, $end)];

        usort($rows, fn (array $a, array $b) => ((int) $a['invoice_ref'] <=> (int) $b['invoice_ref']) ?: strcmp((string) $a['payment_ref'], (string) $b['payment_ref']));

        return $rows;
    }

    /** Uma linha por recibo bancário (`fin_payment_receipt`). */
    private function receipts(int $clientId, string $start, string $end): array
    {
        return $this->allocationBase($clientId, $start, $end)
            ->join('fin_payment_receipt as fpr', 'fpr.ref_charge', '=', 'fc.id')
            ->leftJoin('cmn_files as cf', 'cf.id', '=', 'fpr.ref_file')
            ->orderBy('fpr.ref_charge')
            ->orderBy('fpr.id')
            ->selectRaw('fpr.id as source_id, cf.approved_at as file_approved_at, cf.created_at as file_created_at')
            ->get()
            ->map(fn ($row) => $this->decoratePayment((array) $row, 'R-'.$row->source_id, [$row->file_approved_at, $row->file_created_at, $row->invoice_payment_date]))
            ->all();
    }

    /** Uma linha por cobrança com baixa por boleto (a última) e sem recibo bancário. */
    private function boletos(int $clientId, string $start, string $end): array
    {
        return $this->allocationBase($clientId, $start, $end)
            ->join('fin_payment_boleto as fpb', 'fpb.ref_charge', '=', 'fc.id')
            ->leftJoin('cmn_files as cf', 'cf.id', '=', 'fpb.ref_file')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('fin_payment_receipt as fpr')->whereColumn('fpr.ref_charge', 'fc.id'))
            ->whereRaw('fpb.id = (SELECT MAX(fpb2.id) FROM fin_payment_boleto fpb2 WHERE fpb2.ref_charge = fpb.ref_charge)')
            ->orderBy('fpb.ref_charge')
            ->orderBy('fpb.id')
            ->selectRaw('fpb.id as source_id, fpb.due_to as boleto_due, cf.approved_at as file_approved_at, cf.created_at as file_created_at')
            ->get()
            ->map(fn ($row) => $this->decoratePayment((array) $row, 'B-'.$row->source_id, [$row->invoice_payment_date, $row->boleto_due, $row->file_approved_at, $row->file_created_at]))
            ->all();
    }

    /** Cobranças quitadas no período sem recibo nem boleto (baixa digitada na própria cobrança). */
    private function settlements(int $clientId, string $start, string $end): array
    {
        return $this->allocationBase($clientId, $start, $end)
            ->whereIn('fc.status', self::SETTLED)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('fin_payment_receipt as fpr')->whereColumn('fpr.ref_charge', 'fc.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('fin_payment_boleto as fpb')->whereColumn('fpb.ref_charge', 'fc.id'))
            ->orderBy('fc.id')
            ->selectRaw('fc.id as source_id')
            ->get()
            ->map(fn ($row) => $this->decoratePayment((array) $row, 'S-'.$row->source_id, [$row->invoice_payment_date]))
            ->all();
    }

    private function allocationBase(int $clientId, string $start, string $end): Builder
    {
        return DB::table('fin_charges as fc')
            ->join('embarque as e', 'e.embarque_id', '=', 'fc.ref_booking')
            ->where('e.cliente_id', $clientId)
            ->whereRaw('DATE(fc.created_at) BETWEEN ? AND ?', [$start, $end])
            ->selectRaw('fc.id as invoice_ref, fc.num_nota_fiscal, COALESCE(fc.total_value, 0) as total_value, COALESCE(fc.received_value, 0) as received_value, COALESCE(fc.discount, 0) as discount, fc.created_at as invoice_issue_date, fc.payment_date as invoice_payment_date, fc.due_date as invoice_due_date, fc.status');
    }

    /**
     * Campos calculados da cobrança: valor recebido efetivo, saldo, pago a maior, situação e dias de atraso.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        $status = (int) $row['status'];
        $total = (float) $row['total_value'];
        $discount = (float) $row['discount'];
        $received = $this->receivedValue($status, $total, $discount, (float) $row['received_value']);
        $balance = round($total - $discount - $received, 2);

        $row['total_value'] = $total;
        $row['received_value'] = $received;
        $row['payment_date'] = $this->paymentDate($status, [$row['payment_date']], $row['due_date']);
        $row['balance'] = $balance;
        $row['overpayment'] = max(0, round($received - ($total - $discount), 2));
        $row['balance_open'] = in_array($status, self::SETTLED, true) ? 0.0 : $balance;
        $row['match_status'] = match (true) {
            in_array($status, self::SETTLED, true) => 'Fully Matched',
            $received > 0.01 => 'Partially Matched',
            default => 'Open',
        };

        $due = $this->validDate($row['due_date']);
        $days = $due ? (int) Carbon::parse($due)->startOfDay()->diffInDays(today(), false) : 0;
        $row['days_overdue'] = $days > 0 ? $days : null;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<?string>  $dates  candidatas à data de pagamento, em ordem de preferência
     * @return array<string, mixed>
     */
    private function decoratePayment(array $row, string $paymentRef, array $dates): array
    {
        $status = (int) $row['status'];
        $total = (float) $row['total_value'];
        $discount = (float) $row['discount'];
        $received = $this->receivedValue($status, $total, $discount, (float) $row['received_value']);
        $net = $total - $discount;
        $due = $row['invoice_due_date'];

        $row['payment_ref'] = $paymentRef;
        $row['total_value'] = $total;
        // Legado: o valor alocado é sempre o total da cobrança (o valor do recibo não entra).
        $row['amount_applied'] = $total;
        $row['received_value'] = $received;
        $row['payment_date'] = $this->paymentDate($status, $dates, $due);
        $row['invoice_payment_date'] = $this->paymentDate($status, [$row['invoice_payment_date']], $due);
        $row['allocation_status'] = match (true) {
            in_array($status, self::SETTLED, true) => 'Fully Allocated',
            $received > 0.01 && $received + 0.01 < $net => 'Partially Allocated',
            default => 'Receipted',
        };

        return $row;
    }

    /** Baixa manual sem valor recebido: considera o total líquido como recebido. */
    private function receivedValue(int $status, float $total, float $discount, float $received): float
    {
        return $status === 3 && $received <= 0.01 ? round($total - $discount, 2) : round($received, 2);
    }

    /**
     * Primeira data válida; baixa manual (status 3) sem nenhuma usa o vencimento.
     *
     * @param  list<?string>  $candidates
     */
    private function paymentDate(int $status, array $candidates, ?string $dueDate): ?string
    {
        foreach ($candidates as $candidate) {
            if ($date = $this->validDate($candidate)) {
                return $date;
            }
        }

        return $status === 3 ? $this->validDate($dueDate) : null;
    }

    private function validDate(?string $date): ?string
    {
        return filled($date) && ! str_starts_with($date, '0000-00-00') ? $date : null;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function sum(array $rows, string $key): float
    {
        return round(array_sum(array_column($rows, $key)), 2);
    }
}
