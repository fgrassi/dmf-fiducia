<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\OrdemPagamentoItem;
use Illuminate\Support\Facades\DB;

/**
 * atualizaValorUnitario, editTaxa, atualizaIndice e habilitaDesabilita (legado): edições do item da ordem. Sem histórico.
 *
 * Preserva o legado: com moeda real, mudar o índice multiplica o valor atual do item pelo novo índice (e não o valor
 * unitário), então mudar o índice duas vezes acumula.
 */
class EditOtherOrderItem
{
    private const REAL = 1;

    public function unitValue(OrdemPagamentoItem $item, float $unit): OrdemPagamentoItem
    {
        $item->unitary_value = $unit;
        $item->item_value = (int) $item->ref_currency === self::REAL
            ? $unit * (float) $item->indice
            : $unit * (float) $item->current_rate_value * (float) $item->indice;
        $item->save();

        return $item;
    }

    /** editTaxa: além do item, atualiza o total da ordem quando a soma é positiva. */
    public function rate(OrdemPagamentoItem $item, float $rate): OrdemPagamentoItem
    {
        $item->current_rate_value = $rate;
        $item->item_value = $rate * (float) $item->unitary_value * (float) $item->indice;
        $item->save();

        $total = (float) DB::table('ordem_pagamento_item')->where('ordem_pagamento_id', $item->ordem_pagamento_id)->sum('item_value');

        if ($total > 0) {
            DB::table('ordem_pagamento')->where('ordem_pagamento_id', $item->ordem_pagamento_id)->update(['total_value' => $total]);
        }

        return $item;
    }

    public function index(OrdemPagamentoItem $item, float $index): OrdemPagamentoItem
    {
        $item->indice = $index;
        $item->item_value = (int) $item->ref_currency === self::REAL
            ? $index * (float) $item->item_value
            : $index * (float) $item->unitary_value * (float) $item->current_rate_value;
        $item->save();

        return $item;
    }

    /** @return bool o item ficou ligado */
    public function toggle(OrdemPagamentoItem $item): bool
    {
        $enabled = (int) $item->status !== 1;
        $item->status = $enabled ? 1 : null;
        $item->save();

        return $enabled;
    }
}
