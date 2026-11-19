<?php

namespace App\Actions\Trading;

use Illuminate\Support\Facades\DB;

/**
 * trading/salvaContatoDespachante (legado): contatos do despachante que recebem pela trading (tipo t/f/a e se recebem
 * relatório). Regrava os vínculos da trading com esse despachante; quem não está na lista deixa de ser vinculado. Só entram contatos ativos desse despachante (o id vem do navegador).
 */
class SyncTradingBrokerContacts
{
    /** @param  array<int, array{contact_id: int, linked: bool, tipo: string, relatorio: string}>  $contacts */
    public function __invoke(int $tradingId, int $brokerId, array $contacts): void
    {
        $valid = DB::table('despachante_contato')->where('ref_despachante', $brokerId)->where('status', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($tradingId, $brokerId, $contacts, $valid) {
            DB::table('trading_contato_despachante')->where('trading_id', $tradingId)->where('despachante_id', $brokerId)->delete();

            DB::table('trading_contato_despachante')->insert(collect($contacts)
                ->filter(fn (array $contact) => $contact['linked'] && in_array((int) $contact['contact_id'], $valid, true))
                ->map(fn (array $contact) => [
                    'trading_id' => $tradingId,
                    'despachante_id' => $brokerId,
                    'despachante_contato_id' => $contact['contact_id'],
                    'tipo' => $contact['tipo'],
                    'relatorio' => $contact['relatorio'],
                ])
                ->all());
        });
    }
}
