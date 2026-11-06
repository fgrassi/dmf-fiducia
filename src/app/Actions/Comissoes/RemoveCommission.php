<?php

namespace App\Actions\Comissoes;

use Illuminate\Support\Facades\DB;

/** executeRemoveComissao (booking, legado): apaga a comissão e registra "Comissão removida". Os pagamentos já lançados ficam (como no legado). */
class RemoveCommission
{
    public function __construct(private CommissionTracking $tracking) {}

    public function __invoke(int $commissionId, int $userId): void
    {
        DB::transaction(function () use ($commissionId, $userId) {
            $commission = DB::table('comissao_embarque')->where('comissao_embarque_id', $commissionId)->first();

            if (! $commission) {
                return;
            }

            ($this->tracking)('Comissão removida', 'd', $commission, $userId);
            DB::table('comissao_embarque')->where('comissao_embarque_id', $commissionId)->delete();
        });
    }
}
