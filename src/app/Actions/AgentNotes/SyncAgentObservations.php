<?php

namespace App\Actions\AgentNotes;

use Illuminate\Support\Facades\DB;

/**
 * resumoNotasAgentes/gravaObs e delete (legado): histórico de observações do agente. A tela mostra todas numa lista só: as
 * linhas com "id" continuam como estão (o legado não edita), as que sumiram são excluídas e as sem "id" são incluídas.
 */
class SyncAgentObservations
{
    /** @param  list<array{id?: int|string|null, data_observacao: string, observacao: string}>  $items */
    public function __invoke(int $agentId, array $items, int $userId): void
    {
        $kept = collect($items)->pluck('id')->filter()->all();

        DB::transaction(function () use ($agentId, $items, $userId, $kept) {
            DB::table('agente_observacao')->where('agente_id', $agentId)->whereNotIn('agente_observacao_id', $kept)->delete();

            foreach (collect($items)->reject(fn (array $item) => filled($item['id'] ?? null)) as $item) {
                DB::table('agente_observacao')->insert([
                    'agente_id' => $agentId,
                    'observacao' => $item['observacao'],
                    'data_observacao' => $item['data_observacao'],
                    'created_at' => now(),
                    'created_by' => $userId,
                ]);
            }
        });
    }
}
