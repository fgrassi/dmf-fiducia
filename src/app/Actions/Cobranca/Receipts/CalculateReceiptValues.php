<?php

namespace App\Actions\Cobranca\Receipts;

use App\Actions\Cobranca\Nfse\EmissionGroups;
use App\Models\FinCharges;
use DomainException;

/**
 * calcularValorRecibos (Cobranca::recalcularValoresRecibos, legado): regrava o valor de todos os recibos.
 * Um recibo: recebido + juros + multa − desconto. Vários: um por grupo de NFS-e, na ordem de criação.
 */
class CalculateReceiptValues
{
    public function __construct(private EmissionGroups $groups) {}

    /** @return array<int, float> id do recibo => valor */
    public function __invoke(FinCharges $charge): array
    {
        $receipts = $charge->receipts()->orderBy('id')->get();

        if ($receipts->count() <= 1) {
            $receipts->each->forceFill(['valor' => round((float) $charge->received_value + (float) $charge->fine
                + (float) $charge->interest - (float) $charge->discount, 2)])->each->save();

            return $receipts->pluck('valor', 'id')->map(fn ($v) => (float) $v)->all();
        }

        $groups = ($this->groups)($charge);

        if (count($groups) !== $receipts->count()) {
            throw new DomainException("Não foi possível calcular automaticamente: quantidade de recibos ({$receipts->count()}) difere dos grupos de NFS-e ("
                .count($groups).'). Edite o valor manualmente.');
        }

        $hasResidual = collect($groups)->contains('residual', true);
        $last = array_key_last($groups);

        foreach ($receipts->values() as $n => $receipt) {
            $extras = $groups[$n]['residual'] || (! $hasResidual && $n === $last);
            $receipt->forceFill(['valor' => round($groups[$n]['total'] + ($extras ? (float) $charge->fine + (float) $charge->interest : 0.0), 2)])->save();
        }

        return $receipts->pluck('valor', 'id')->map(fn ($v) => (float) $v)->all();
    }
}
