<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcessoManual;
use Illuminate\Support\Facades\DB;

/** removeCapaManual (legado): exclui a capa manual e o resumo dela. */
class DeleteManualCover
{
    public function __invoke(CapaProcessoManual $cover): void
    {
        DB::transaction(function () use ($cover) {
            DB::table('resumo_resultado')->where('capa_processo_manual_id', $cover->getKey())->delete();
            $cover->delete();
        });
    }
}
