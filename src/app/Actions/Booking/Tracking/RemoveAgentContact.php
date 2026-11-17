<?php

namespace App\Actions\Booking\Tracking;

use App\Actions\Booking\BookingLog;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * removerContatoAgente (legado): tira um contato do agente dos destinatários do rastreamento só desta reserva
 * (embarque_agente_contato_excluido); o cadastro do agente não muda. Fica registrado no histórico da reserva.
 */
class RemoveAgentContact
{
    public function __construct(private BookingLog $log) {}

    /** @throws DomainException */
    public function __invoke(Embarque $booking, int $contactId, int $userId): void
    {
        $contact = DB::table('agente_contato')->where('id', $contactId)->first(['name', 'email', 'ref_pessoa']);

        throw_if(! $contact || (int) $contact->ref_pessoa !== (int) $booking->pessoa_id_representante, DomainException::class, 'Contato não encontrado para o agente desta reserva.');

        DB::transaction(function () use ($booking, $contactId, $contact, $userId) {
            DB::table('embarque_agente_contato_excluido')->insertOrIgnore(['embarque_id' => $booking->getKey(), 'agente_contato_id' => $contactId, 'created_at' => now()->toDateTimeString()]);

            $agent = DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_representante)->value('nome');
            $user = DB::table('usuario')->where('usuario_id', $userId)->value('nome');

            $this->log->track($booking->getKey(), 'Contato do agente removido', "O contato {$contact->name} ({$contact->email}) do agente {$agent} foi removido deste booking por {$user}.", $userId, 'a');
        });
    }
}
