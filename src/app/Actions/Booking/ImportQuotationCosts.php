<?php

namespace App\Actions\Booking;

use App\Models\Embarque;
use App\Models\FinExpensesValues;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * booking/importarCustosCotacao (legado): traz para a reserva os itens da cotação.
 *
 * Importação: apaga todas as despesas da reserva e recria a partir da cotação (marítima ou aérea; item com tipo de container
 * só entra se a reserva tem esse container). Exportação: não apaga; atualiza a despesa de mesma despesa e mesmo custo
 * (updated_by = 1, o usuário do sistema) ou cria uma nova. Como no legado, não grava histórico nem devolve a reserva à fila de
 * cobrança/pagamento.
 *
 * Diferença do legado, por decisão: reserva sem cotação (ou de modal sem cotação, como o seguro) é recusada antes de apagar
 * qualquer coisa; o legado apagava as despesas e depois falhava. O banco recusa apagar despesa que cobrança, ordem de
 * pagamento, baixa de TA ou capa já usam (RESTRICT): o legado quebrava no meio do laço, deixando a reserva pela metade;
 * aqui é tudo ou nada, com mensagem. A tela avisa antes (warning()).
 */
class ImportQuotationCosts
{
    private const SYSTEM_USER = 1;

    /** Tabelas que, além das de DeleteExpense::USED_IN, apontam para a despesa com chave estrangeira RESTRICT: tabela => [coluna, documento]. */
    private const ALSO_USED_IN = [
        'ordem_pagamento_item' => ['ref_expense', 'uma Ordem de Pagamento'],
        'ta_baixa' => ['fin_expenses_values_id', 'uma baixa de TA'],
        'capa_processo_despesa_item' => ['fin_expenses_values_id', 'uma Capa de Contabilidade'],
    ];

    /** Aviso para a confirmação: documentos que já usam as despesas que a importação vai apagar. */
    public function warning(Embarque $booking): ?string
    {
        if ($booking->impor_expor !== 'i') {
            return null;
        }

        $ids = $booking->expenses()->pluck('id');

        $documents = collect(DeleteExpense::USED_IN)
            ->map(fn (string $document, string $table) => [$table, 'ref_expense', $document])
            ->merge(collect(self::ALSO_USED_IN)->map(fn (array $use, string $table) => [$table, ...$use]))
            ->filter(fn (array $use) => DB::table($use[0])->whereIn($use[1], $ids)->exists())
            ->map(fn (array $use) => $use[2])
            ->unique()
            ->values();

        if (DB::table('capa_processo')->where('embarque_id', $booking->getKey())->exists()) {
            $documents->push('uma Capa de Contabilidade');
        }

        return $documents->isEmpty()
            ? null
            : 'Esta reserva já tem '.$documents->implode(', ').' sobre as despesas. A importação apaga todas as despesas da reserva e os documentos emitidos ficam sem a despesa de origem.';
    }

    /**
     * @return int despesas gravadas
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, int $userId): int
    {
        $items = $this->items($booking);

        try {
            return DB::transaction(fn () => $booking->impor_expor === 'i'
                ? $this->replaceAll($booking, $items, $userId)
                : $this->merge($booking, $items, $userId));
        } catch (QueryException $e) {
            // O banco recusa apagar despesa com cobrança, ordem, baixa de TA ou capa (RESTRICT); o legado quebrava no meio do laço.
            throw_unless($e->getCode() === '23000', $e);

            throw new DomainException('Não foi possível importar: há cobrança, ordem de pagamento, baixa de TA ou capa usando as despesas desta reserva. Nada foi alterado.');
        }
    }

    /** @return Collection<int, object> */
    private function items(Embarque $booking): Collection
    {
        [$table, $key, $quotation] = match (true) {
            $booking->impor_expor === 'e' => ['cotacao_expo_item', 'cotacao_expo_id', $booking->cotacao_expo_id],
            $booking->impor_expor === 'i' && $booking->tipo_embarque === 'm' => ['cotacao_impo_item', 'cotacao_impo_id', $booking->cotacao_impo_id],
            $booking->impor_expor === 'i' && $booking->tipo_embarque === 'a' => ['cotacao_impo_aereo_item', 'cotacao_impo_aereo_id', $booking->cotacao_impo_aereo_id],
            default => throw new DomainException('Esta reserva não importa custos de cotação.'),
        };

        if (! $quotation) {
            throw new DomainException('A reserva não tem cotação.');
        }

        return DB::table($table)->where($key, $quotation)->orderBy("{$table}_id")->get();
    }

    private function replaceAll(Embarque $booking, $items, int $userId): int
    {
        $containers = DB::table('mercadoria')->where('embarque_id', $booking->getKey())->pluck('tipo_container_id')->unique();

        FinExpensesValues::where('ref_embarque', $booking->getKey())->delete();

        $rows = $items->filter(fn (object $i) => ! $i->tipo_container_id || $containers->contains($i->tipo_container_id));

        foreach ($rows as $item) {
            (new FinExpensesValues)->forceFill([
                ...$this->values($item, $booking),
                ...($booking->tipo_embarque === 'a' ? ['selling_rate_perc' => $item->perc] : []),
                'created_by' => $userId,
                'created_at' => today()->toDateString(),
            ])->save();
        }

        return $rows->count();
    }

    private function merge(Embarque $booking, $items, int $userId): int
    {
        foreach ($items as $item) {
            $expense = FinExpensesValues::where('ref_embarque', $booking->getKey())
                ->where('ref_expense', $item->despesa_id)
                ->where('buying_rate', $item->valor_compra)
                ->orderBy('id')
                ->first();

            $expense ??= (new FinExpensesValues)->forceFill([
                'created_by' => $userId,
                'created_at' => today()->toDateString(),
            ]);

            $values = [...$this->values($item, $booking), 'all_in' => $item->all_in];

            if ((int) $item->despesa_id === SaveExpense::HIDDEN_FLAGS_EXPENSE) {
                $values = Arr::except(app(SaveExpense::class)->withHiddenDocumentFlags($values), 'profit');
            }

            $expense->forceFill([
                ...$values,
                ...($expense->exists ? ['updated_by' => self::SYSTEM_USER, 'updated_at' => today()->toDateString()] : []),
            ])->save();
        }

        return $items->count();
    }

    /** @return array<string, mixed> */
    private function values(object $item, Embarque $booking): array
    {
        return [
            'ref_embarque' => $booking->getKey(),
            'ref_expense' => $item->despesa_id,
            'buying_rate' => $item->valor_compra,
            'ref_currency_br' => $item->moeda_compra_id,
            'selling_rate' => $item->valor_venda,
            'ref_currency_sr' => $item->moeda_venda_id,
            'billing' => $item->tipo_cobranca,
            'sr_min' => $item->venda_minimo,
            'br_min' => $item->compra_minimo,
            ...($item->tipo_container_id ? ['ref_equipment' => $item->tipo_container_id] : []),
        ];
    }
}
