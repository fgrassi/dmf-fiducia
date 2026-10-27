<?php

namespace App\Actions\Cobranca\Generation;

use App\Models\ControleInvoiceMaster;
use App\Models\ControleInvoiceMasterLog;
use Illuminate\Support\Facades\DB;

/**
 * forcarCobranca / executeBatchLiberar (controleinvoice legado): o pendente vira liberado (aguardando arquivo), registra a
 * cobranca_manual da reserva e gera as ICs numa rodada só. Reserva com cobranca_manual nos últimos 30s é ignorada
 * (duplo clique / reenvio). Diferente do GenerateManualCharge, não avisa o financeiro de geração manual.
 */
class ChargeInvoiceControls
{
    private const DEDUPE_SECONDS = 30;

    public function __construct(private GenerateCharges $generate) {}

    /**
     * @param  iterable<ControleInvoiceMaster>  $controls
     * @return int controles enviados para cobrança
     */
    public function __invoke(iterable $controls, string $releaseNote, int $userId): int
    {
        $sources = [];

        foreach ($controls as $control) {
            $recent = DB::table('cobranca_manual')
                ->where('embarque_id', $control->embarque_id)
                ->where('created_at', '>=', now()->subSeconds(self::DEDUPE_SECONDS))
                ->exists();

            if ($recent) {
                continue;
            }

            $control->releaseForBilling($releaseNote, $userId);

            $id = DB::table('cobranca_manual')->insertGetId([
                'embarque_id' => $control->embarque_id,
                'created_at' => now(),
                'created_by' => $userId,
            ]);
            $control->log(ControleInvoiceMasterLog::CHARGE, 'Cobrança manual disparada.', $userId, $control->status, $control->status);

            $sources[] = [$control->embarque_id, fn () => DB::table('cobranca_manual')->where('cobranca_manual_id', $id)->update(['control' => 1])];
        }

        if ($sources !== []) {
            ($this->generate)($sources, null, $userId);
        }

        return count($sources);
    }
}
