<?php

namespace App\Actions\Cobranca\Recalculation;

use App\Actions\Cobranca\Items\DeleteItems;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cobranca::recalculaCobranca + enviarAposRecalculoCobranca (legado, cron/recalculaCobranca): o recálculo que o job
 * UpdateCharge da intranet pede por HTTP. Diferente do botão da tela: não olha a regra do armador, não refaz o IOF
 * (o total gravado é só a soma dos itens) e não tem usuário. Depois, substitui a NFS-e, regenera a IC e reenvia.
 * As mensagens de erro ficam em inglês como no legado: a intranet as mostra ao usuário.
 */
class RecalculateChargeByService
{
    public function __construct(
        private ChargeRates $rates,
        private RecalculateCharge $recalculate,
        private AfterRecalculation $after,
    ) {}

    /** @throws DomainException o erro que o legado devolvia em `error` */
    public function __invoke(FinCharges $charge): void
    {
        if ($charge->status !== ChargeStatus::Open) {
            throw new DomainException('Payd invoices cannot be update');
        }

        if (DeleteItems::hasNfseInProgress($charge)) {
            throw new DomainException('Invoices with NFSe still processing cannot be recalculated');
        }

        if (! $this->rates->upToDate($charge)) {
            throw new DomainException('One or more currency are outdated. Please, try again later');
        }

        DB::transaction(function () use ($charge) {
            $this->recalculate->recalculateItems($charge);

            $charge->forceFill([
                'total_value' => (float) $charge->items()->sum('item_value'),
                'updated_at' => now()->toDateString(),
            ])->save();

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'recalculo',
                'historico' => "Cobrança recalculada (serviço/cron). Total: {$charge->total_value}",
                'status_anterior' => $charge->status->value,
                'status_novo' => $charge->status->value,
                'created_at' => now(),
            ]);

            DB::table('transborda')->insert([
                'usuario_id' => 1,
                'embarque_id' => $charge->ref_booking,
                'data_reg' => now()->toDateString(),
                'descr' => $charge->embarque->impor_expor === 'i' ? 'Payment request resent to Cnee' : 'Payment request resent to Shipper',
                'ref_frase_transborda' => 59,
            ]);
        });

        ($this->after)($charge->refresh(), null);
    }
}
