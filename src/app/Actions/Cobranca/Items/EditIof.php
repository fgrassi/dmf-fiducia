<?php

namespace App\Actions\Cobranca\Items;

use App\Models\FinCharges;
use App\Models\FinChargesLog;
use DomainException;
use Illuminate\Support\Facades\DB;

/** EditIof (legado): grava o IOF digitado, total = soma dos itens + IOF, e registra no histórico. */
class EditIof
{
    /** @param  string  $iof  digitado em pt-BR ("1.234,56") */
    public function __invoke(FinCharges $charge, string $iof, ?int $userId): FinCharges
    {
        $value = str_replace(',', '.', str_replace('.', '', trim($iof)));

        if (! is_numeric($value)) {
            throw new DomainException('Valor do IOF inválido.');
        }

        $previous = $charge->getRawOriginal('valor_iof');

        DB::transaction(function () use ($charge, $value, $previous, $userId) {
            $items = (float) $charge->items()->sum('item_value');

            $charge->forceFill(['valor_iof' => $value, 'total_value' => $items + (float) $value])->save();

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'edit_iof',
                'historico' => "IOF alterado de {$previous} para {$value}. Total: {$charge->total_value}",
                'status_anterior' => $charge->status->value,
                'status_novo' => $charge->status->value,
                'created_at' => now(),
                'created_by' => $userId,
            ]);
        });

        return $charge;
    }
}
