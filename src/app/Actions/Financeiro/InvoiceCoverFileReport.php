<?php

namespace App\Actions\Financeiro;

use App\Models\CapaProcessoArquivo;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * relatorioNfCapa (legado), "NF x arquivo de capa": NFS-e autorizadas emitidas no período contra o arquivo (pasta) de capa escolhido.
 *
 * A nota liga à capa pela cobrança: item de receita ou de despesa da capa que esteja na cobrança. Cada nota cai nas listas:
 * - `anterior` / `posterior` / `mesmo_mes`: tem capa em outro arquivo, de mês anterior, posterior ou igual ao do arquivo escolhido;
 * - `nenhum`: nenhuma capa arquivada.
 * Uma nota pode estar em mais de uma lista (e também "no arquivo"). Nas posteriores, o custo em aberto é a despesa da capa menos
 * o que já está em ordem de pagamento paga; o total não repete a mesma capa.
 *
 * @phpstan-type Input array{data_ini?: ?string, data_fim?: ?string, capa_processo_arquivo_id?: int|string|null}
 */
class InvoiceCoverFileReport
{
    private const CHUNK = 400;

    /**
     * @param  Input  $input
     * @return array{periodo: string, arquivo_nome: string, resumo: array<string, float|int>, anterior: list<array<string, mixed>>, posterior: list<array<string, mixed>>, nenhum: list<array<string, mixed>>, mesmo_mes: list<array<string, mixed>>}
     *
     * @throws DomainException período ou arquivo inválido (mesmas mensagens do legado)
     */
    public function __invoke(array $input): array
    {
        $start = $this->date($input['data_ini'] ?? null);
        $end = $this->date($input['data_fim'] ?? null);

        if ($start === null || $end === null) {
            throw new DomainException('Informe a data inicial e a data final da emissão.');
        }

        if ($start > $end) {
            throw new DomainException('A data inicial deve ser anterior ou igual à data final.');
        }

        if (blank($input['capa_processo_arquivo_id'] ?? null)) {
            throw new DomainException('Selecione o arquivo de capa.');
        }

        $file = CapaProcessoArquivo::query()->find((int) $input['capa_processo_arquivo_id']) ?? throw new DomainException('Arquivo de capa não encontrado.');

        $invoices = $this->invoices($start, $end);
        $links = $this->links(array_column($invoices, 'ref_charge'));
        $referenceMonth = (int) $file->ano * 100 + (int) $file->mes;
        $lists = ['anterior' => [], 'posterior' => [], 'nenhum' => [], 'mesmo_mes' => []];
        $inFile = [];

        foreach ($invoices as $invoice) {
            $archives = $this->archives($links[$invoice['ref_charge']] ?? []);

            if (isset($archives[$file->getKey()])) {
                $inFile[$invoice['nfse_id']] = true;
            }

            if ($archives === []) {
                $lists['nenhum'][] = $this->row($invoice, '', []);

                continue;
            }

            foreach ($archives as $archiveId => $archive) {
                if ($archiveId === (int) $file->getKey()) {
                    continue;
                }

                $key = match ($archive['month'] <=> $referenceMonth) {
                    -1 => 'anterior',
                    1 => 'posterior',
                    default => 'mesmo_mes',
                };
                $lists[$key][] = $this->row($invoice, $archive['name'], $archive['cover_ids']);
            }
        }

        $open = $this->openCosts(array_merge([], ...array_column($lists['posterior'], 'capa_ids')));
        $counted = [];
        $totalOpen = 0.0;

        foreach ($lists['posterior'] as $i => $row) {
            $lists['posterior'][$i]['custo_em_aberto'] = round(array_sum(array_map(fn (int $id) => $open[$id] ?? 0, $row['capa_ids'])), 2);

            foreach ($row['capa_ids'] as $coverId) {
                if (! isset($counted[$coverId])) {
                    $counted[$coverId] = true;
                    $totalOpen += $open[$coverId] ?? 0;
                }
            }
        }

        foreach ($lists as $key => $rows) {
            usort($rows, fn (array $a, array $b) => [$a['data_iso'], $a['numero_nf'], $a['booking'], $a['arquivo_nome']] <=> [$b['data_iso'], $b['numero_nf'], $b['booking'], $b['arquivo_nome']]);
            $lists[$key] = $rows;
        }

        return [
            'periodo' => Carbon::parse($start)->format('d/m/Y').' a '.Carbon::parse($end)->format('d/m/Y'),
            'arquivo_nome' => (string) $file->nome,
            'resumo' => [
                'no_arquivo' => count($inFile),
                'anterior' => $this->countInvoices($lists['anterior']),
                'posterior' => $this->countInvoices($lists['posterior']),
                'nenhum' => $this->countInvoices($lists['nenhum']),
                'mesmo_mes' => $this->countInvoices($lists['mesmo_mes']),
                'custo_em_aberto' => round($totalOpen, 2),
            ],
            ...$lists,
        ];
    }

    /** @return list<array{nfse_id: int, ref_charge: int, data_iso: string, numero_nf: string, valor_nf: float, booking: string}> */
    private function invoices(string $start, string $end): array
    {
        // A data de emissão está no payload (pode diferir do created_at em até um dia ou dois): busca com folga e filtra depois.
        $rows = DB::table('fin_charges_nfse as n')
            ->join('fin_charges as fc', 'fc.id', '=', 'n.ref_charge')
            ->leftJoin('embarque as e', 'e.embarque_id', '=', 'fc.ref_booking')
            ->where('n.status', 'autorizada')
            ->where('n.created_at', '>=', Carbon::parse($start)->subDay()->toDateString())
            ->where('n.created_at', '<', Carbon::parse($end)->addDays(2)->toDateString())
            ->get(['n.fin_charges_nfse_id', 'n.ref_charge', 'n.created_at', 'n.chave_acesso', 'n.valor_servicos', 'n.payload_json', 'fc.num_nota_fiscal', 'e.booking']);

        $invoices = [];

        foreach ($rows as $row) {
            $issued = $this->issueDate($row->payload_json, $row->created_at);

            if ($issued === null || $issued < $start || $issued > $end) {
                continue;
            }

            $invoices[(int) $row->fin_charges_nfse_id] = [
                'nfse_id' => (int) $row->fin_charges_nfse_id,
                'ref_charge' => (int) $row->ref_charge,
                'data_iso' => $issued,
                'numero_nf' => $this->invoiceNumber($row->chave_acesso, $row->num_nota_fiscal),
                'valor_nf' => (float) $row->valor_servicos,
                'booking' => trim((string) $row->booking),
            ];
        }

        return array_values($invoices);
    }

    /**
     * Capas ligadas à cobrança pelo item de receita ou de despesa.
     *
     * @param  list<int>  $chargeIds
     * @return array<int, array<int, object>> cobrança => capa => linha (capa e arquivo)
     */
    private function links(array $chargeIds): array
    {
        $links = [];

        foreach (array_chunk(array_values(array_unique($chargeIds)), self::CHUNK) as $chunk) {
            $query = fn (string $itemTable) => DB::table('fin_charges_items as fci')
                ->join('fin_expenses_values as fev', 'fev.id', '=', 'fci.ref_expense')
                ->join("{$itemTable} as item", 'item.fin_expenses_values_id', '=', 'fev.id')
                ->join('capa_processo as cp', 'cp.capa_process_id', '=', 'item.capa_processo_id')
                ->leftJoin('capa_processo_arquivo as cpa', 'cpa.capa_processo_arquivo_id', '=', 'cp.capa_processo_arquivo_id')
                ->whereIn('fci.ref_charge', $chunk)
                ->selectRaw('fci.ref_charge, cp.capa_process_id, cpa.capa_processo_arquivo_id as arquivo_id, cpa.nome as arquivo_nome, cpa.mes, cpa.ano');

            foreach ($query('capa_processo_despesa_item')->union($query('capa_processo_receita_item'))->get() as $row) {
                $links[(int) $row->ref_charge][(int) $row->capa_process_id] = $row;
            }
        }

        return $links;
    }

    /**
     * @param  array<int, object>  $covers
     * @return array<int, array{name: string, month: int, cover_ids: list<int>}> arquivo => capas da nota nele
     */
    private function archives(array $covers): array
    {
        $archives = [];

        foreach ($covers as $coverId => $cover) {
            $archiveId = (int) $cover->arquivo_id;

            if ($archiveId === 0) {
                continue;
            }

            $archives[$archiveId] ??= [
                'name' => filled(trim((string) $cover->arquivo_nome)) ? trim($cover->arquivo_nome) : sprintf('%02d/%d', (int) $cover->mes, (int) $cover->ano),
                'month' => (int) $cover->ano * 100 + (int) $cover->mes,
                'cover_ids' => [],
            ];
            $archives[$archiveId]['cover_ids'][] = $coverId;
        }

        return $archives;
    }

    /**
     * Custo da capa ainda não pago ao fornecedor: despesa da capa menos as ordens pagas (só a diferença positiva por item).
     *
     * @param  list<int>  $coverIds
     * @return array<int, float> capa => valor
     */
    private function openCosts(array $coverIds): array
    {
        $byCover = [];

        foreach (array_chunk(array_values(array_unique($coverIds)), self::CHUNK) as $chunk) {
            $items = DB::table('capa_processo_despesa_item')->whereIn('capa_processo_id', $chunk)->where('fin_expenses_values_id', '>', 0)->get(['capa_processo_id', 'fin_expenses_values_id', 'valor']);

            foreach ($items as $item) {
                $byCover[(int) $item->capa_processo_id][(int) $item->fin_expenses_values_id] = ($byCover[(int) $item->capa_processo_id][(int) $item->fin_expenses_values_id] ?? 0) + (float) $item->valor;
            }
        }

        $paid = $this->paid(array_values(array_unique(array_merge([], ...array_map('array_keys', $byCover)))));

        return collect($byCover)
            ->map(fn (array $items) => round(collect($items)->map(fn (float $value, int $expenseId) => max($value - ($paid[$expenseId] ?? 0), 0))->sum(), 2))
            ->all();
    }

    /**
     * Pago ao fornecedor por item de despesa: ordens do armador pagas (sem o item desabilitado) e ordens de outros pagas.
     *
     * @param  list<int>  $expenseIds
     * @return array<int, float>
     */
    private function paid(array $expenseIds): array
    {
        $paid = [];

        foreach (array_chunk($expenseIds, self::CHUNK) as $chunk) {
            $carrier = DB::table('fin_payment_order_items as fpoi')
                ->join('fin_payment_orders as fpo', 'fpo.id', '=', 'fpoi.ref_payment_order')
                ->where('fpo.status', 1)
                ->whereIn('fpoi.ref_expense', $chunk)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('fin_payment_order_items_disabled as fpoid')
                    ->whereColumn('fpoid.ref_fin_payment_order', 'fpoi.ref_payment_order')
                    ->whereColumn('fpoid.ref_expense', 'fpoi.ref_expense')
                    ->where('fpoid.status', 0))
                ->groupBy('fpoi.ref_expense')
                ->selectRaw('fpoi.ref_expense as expense_id, SUM(fpoi.item_value) as paid');

            $others = DB::table('ordem_pagamento_item as opi')
                ->join('ordem_pagamento as op', 'op.ordem_pagamento_id', '=', 'opi.ordem_pagamento_id')
                ->where('op.status', 1)
                ->whereIn('opi.ref_expense', $chunk)
                ->groupBy('opi.ref_expense')
                ->selectRaw('opi.ref_expense as expense_id, SUM(opi.item_value) as paid');

            foreach ($carrier->unionAll($others)->get() as $row) {
                $paid[(int) $row->expense_id] = ($paid[(int) $row->expense_id] ?? 0) + (float) $row->paid;
            }
        }

        return $paid;
    }

    /** @return array<string, mixed> */
    private function row(array $invoice, string $archiveName, array $coverIds): array
    {
        return [
            'nfse_id' => $invoice['nfse_id'],
            'data_iso' => $invoice['data_iso'],
            'data_nf' => Carbon::parse($invoice['data_iso'])->format('d/m/Y'),
            'numero_nf' => $invoice['numero_nf'],
            'valor_nf' => $invoice['valor_nf'],
            'booking' => $invoice['booking'],
            'arquivo_nome' => $archiveName,
            'capa_ids' => $coverIds,
            'custo_em_aberto' => 0.0,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function countInvoices(array $rows): int
    {
        return count(array_unique(array_column($rows, 'nfse_id')));
    }

    /** Número da NFS-e nacional: 13 dígitos a partir da posição 24 da chave de 50 dígitos; senão o número da cobrança; senão a chave. */
    private function invoiceNumber(mixed $key, mixed $fallback): string
    {
        $digits = preg_replace('/\D/', '', (string) $key);

        if (strlen($digits) === 50 && ltrim(substr($digits, 23, 13), '0') !== '') {
            return ltrim(substr($digits, 23, 13), '0');
        }

        return filled(trim((string) $fallback)) ? trim((string) $fallback) : trim((string) $key);
    }

    /** Emissão do payload (`data_emissao`), senão a criação do registro. */
    private function issueDate(mixed $payloadJson, mixed $createdAt): ?string
    {
        $payload = json_decode((string) $payloadJson, true);

        return (is_array($payload) ? $this->normalizeDate($payload['data_emissao'] ?? null) : null) ?? $this->normalizeDate($createdAt);
    }

    /** Aceita Y-m-d (com hora) ou d/m/Y. */
    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return match (true) {
            (bool) preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) => $m[1],
            (bool) preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $value, $m) => "{$m[3]}-{$m[2]}-{$m[1]}",
            default => null,
        };
    }

    private function date(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->toDateString() : null;
    }
}
