<?php

namespace App\Actions\Comissoes;

use Illuminate\Support\Facades\DB;

/** geraLog do booking (legado) para as comissões: grava "Comissão adicionada/removida" no rastreamento da reserva. */
class CommissionTracking
{
    public function __invoke(string $subject, string $event, object $commission, int $userId): void
    {
        $symbol = DB::table('cmn_currencies')->where('id', $commission->moeda_id)->value('symbol');
        $name = DB::table('comissao_embarque_responsavel')->where('comissao_embarque_responsavel_id', $commission->comissao_embarque_responsavel_id)->value('nome');
        $verb = $event === 'd' ? 'removida' : 'adicionada';

        DB::table('rastreamento')->insert([
            'assunto' => $subject,
            'historico' => "A comissão no valor de {$symbol} ".number_format((float) $commission->valor_unitario, 2, ',', '.')." para {$name} foi {$verb}.",
            'justificativa' => '',
            'ref_embarque' => $commission->embarque_id,
            'evento' => $event,
            'created_at' => now()->toDateString(),
            'created_time' => now()->format('H:i:s'),
            'created_by' => $userId,
        ]);
    }
}
