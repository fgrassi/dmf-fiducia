<?php

namespace App\Actions\Cobranca\Maintenance;

use App\Models\FinCharges;
use App\Models\FinChargesLog;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** EditDueDate (legado): altera o vencimento da cobrança e registra no histórico (fin_charges_log). */
class EditDueDate
{
    public function __invoke(FinCharges $charge, string $date, ?int $userId): FinCharges
    {
        try {
            $new = CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            throw new DomainException('Data de vencimento inválida.');
        }

        $previous = $charge->getRawOriginal('due_date');

        DB::transaction(function () use ($charge, $new, $previous, $userId) {
            $charge->forceFill(['due_date' => $new])->save();

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'edit_due_date',
                'historico' => 'Vencimento alterado de '.($previous ?: '—')." para {$new}",
                'status_anterior' => $charge->status->value,
                'status_novo' => $charge->status->value,
                'created_at' => now(),
                'created_by' => $userId,
            ]);
        });

        return $charge;
    }
}
