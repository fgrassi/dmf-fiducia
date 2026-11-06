<?php

namespace App\Actions\Comissoes;

use Illuminate\Support\Facades\DB;

/** executeAddPagamento (comissoes, legado): lança um pagamento e marca a comissão como paga (mesmo o pagamento parcial). */
class PayCommission
{
    /** @param  array{valor_total:float|int|string, taxa?:float|int|string|null, data_pagamento:string, observacao?:?string}  $data */
    public function __invoke(int $commissionId, array $data, int $userId): void
    {
        DB::transaction(function () use ($commissionId, $data, $userId) {
            DB::table('comissao_embarque_pagamento')->insert([
                'comissao_embarque_id' => $commissionId,
                'valor_total' => (float) $data['valor_total'],
                'taxa' => filled($data['taxa'] ?? null) ? (float) $data['taxa'] : null,
                'data_pagamento' => $data['data_pagamento'],
                'observacao' => $data['observacao'] ?? null,
                'created_at' => now()->format('Y-m-d H:i:s'),
                'created_by' => $userId,
            ]);

            DB::table('comissao_embarque')->where('comissao_embarque_id', $commissionId)->update(['status' => 1]);
        });
    }
}
