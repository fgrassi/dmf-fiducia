<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\Embarque;
use App\Models\OrdemPagamento;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * recalculaDespesas (legado, Outros): refaz os valores dos itens da ordem aberta com as taxas digitadas e, depois, cria ou
 * atualiza a instrução de pagamento de cada parte (um "Due To" por instrução), gravando o CNPJ e os dados bancários
 * informados no cadastro da parte. "Resetar valores" refaz também valor unitário, moeda e índice a partir das despesas da reserva.
 *
 * Preserva o legado: ao resetar, o valor do item usa o custo mais as comissões ligadas, mas o valor unitário gravado é só o custo;
 * instruções já existentes não perdem itens que foram desligados depois; só entram na instrução os itens com status 1.
 */
class RecalculateOtherPaymentOrder
{
    private const ENABLED = 1;

    private const REAL = 1;

    public function __construct(private OtherOrderItemValue $value, private OtherOrderParties $parties) {}

    /**
     * @param  array<int, float>  $rates  moeda => taxa digitada (obrigatória para cada moeda estrangeira dos itens)
     * @param  array<int, array{id?: ?int, name?: ?string, cnpj?: ?string, bank?: ?string}>  $parties  Due To => parte
     * @return array{status: string, message: string} OK ou WARNING (sem item ligado)
     *
     * @throws DomainException
     */
    public function __invoke(OrdemPagamento $order, array $rates, bool $reset, array $parties, int $userId): array
    {
        if ((int) $order->status !== 0) {
            throw new DomainException('A ordem de pagamento já foi paga ou cancelada.');
        }

        return DB::transaction(function () use ($order, $rates, $reset, $parties, $userId) {
            $order->forceFill(['updated_at' => now()->toDateString(), 'updated_by' => $userId])->save();
            $booking = Embarque::findOrFail($order->ref_embarque);

            foreach ($order->items()->orderBy('ordem_pagamento_item_id')->get() as $item) {
                $expense = DB::table('fin_expenses_values')->where('id', $item->ref_expense)->first();
                $rate = (int) $expense->ref_currency_br === self::REAL ? 1.0 : (float) ($rates[$expense->ref_currency_br] ?? 0);

                if ($reset) {
                    $item->forceFill([
                        'unitary_value' => $expense->buying_rate,
                        'ref_currency' => $expense->ref_currency_br,
                        'current_rate_value' => $rate,
                        'item_value' => $this->value->debit($expense, $rate, $this->value->unitWithCommissions($booking, $expense), $booking),
                        'indice' => $this->value->index($expense, $booking),
                    ])->save();
                } else {
                    $item->forceFill([
                        'current_rate_value' => $rate,
                        'item_value' => (float) $item->indice * (float) $item->unitary_value * $rate,
                    ])->save();
                }
            }

            $order->forceFill(['total_value' => (float) $order->items()->sum('item_value')])->save();

            return $this->instructions($order, $parties);
        });
    }

    /** @param  array<int, array{id?: ?int, name?: ?string, cnpj?: ?string, bank?: ?string}>  $parties */
    private function instructions(OrdemPagamento $order, array $parties): array
    {
        $byDueTo = DB::table('ordem_pagamento_item as i')
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->where('i.ordem_pagamento_id', $order->getKey())
            ->where('i.status', self::ENABLED)
            ->orderBy('i.ordem_pagamento_item_id')
            ->get(['i.ordem_pagamento_item_id as id', 'i.item_value', 'v.ref_due_to'])
            ->groupBy('ref_due_to');

        if ($byDueTo->isEmpty()) {
            return ['status' => 'WARNING', 'message' => 'Nenhum item habilitado para gerar a instrução de pagamento'];
        }

        $known = $this->parties->forOrder($order);

        foreach ($byDueTo as $dueTo => $items) {
            $party = $parties[$dueTo] ?? [];
            // A classe vem do servidor (Due To / instrução), nunca do formulário; o id precisa existir nela.
            $class = $known[$dueTo]['class'] ?? 'Pessoa';
            $partyId = filled($party['id'] ?? null) ? (int) $party['id'] : null;
            $partyId = $partyId && $this->parties->lookup($class, $partyId) ? $partyId : null;

            if ($partyId) {
                $this->parties->updateRegistry($class, $partyId, $party['cnpj'] ?? null, $party['bank'] ?? null);
            }

            $fields = ['valor_iof' => $order->valor_iof, 'recebedor' => $party['name'] ?? null, 'cnpj_recebedor' => $party['cnpj'] ?? null, 'dados_bancarios' => $party['bank'] ?? null];
            $instruction = $this->parties->instructionId($order, (int) $dueTo);

            $instruction
                ? DB::table('instrucao_pagamento_outros')->where('instrucao_pagamento_outros_id', $instruction)->update($fields)
                : $instruction = (int) DB::table('instrucao_pagamento_outros')->insertGetId(['created_at' => now(), 'ordem_pagamento_id' => $order->getKey()] + $fields);

            if ($partyId) {
                $this->parties->linkToInstruction($instruction, $partyId, $class);
            }

            foreach ($items as $item) {
                $line = DB::table('instrucao_pagamento_item_outros')->where('instrucao_pagamento_outros_id', $instruction)->where('ordem_pagamento_item_id', $item->id);

                $line->exists()
                    ? $line->update(['valor' => $item->item_value])
                    : DB::table('instrucao_pagamento_item_outros')->insert(['instrucao_pagamento_outros_id' => $instruction, 'ordem_pagamento_item_id' => $item->id, 'valor' => $item->item_value]);
            }
        }

        return ['status' => 'OK', 'message' => 'Instrução de pagamento atualizada com sucesso'];
    }
}
