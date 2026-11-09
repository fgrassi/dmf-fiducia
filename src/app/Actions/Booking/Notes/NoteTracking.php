<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DmfUtil::geraLog + getDnRastreabilidade / getCnRastreabilidade / getStatus*Rastreabilidade (legado):
 * o que as notas da reserva gravam no rastreamento.
 */
class NoteTracking
{
    private const SUBJECTS = ['i' => 'Cadastro', 'u' => 'Atualizado', 'd' => 'Apagado', 'e' => 'Enviado'];

    /** Registra o evento da nota (i, u, d ou e); a justificativa só existe na remoção. */
    public function note(NoteType $type, int $noteId, string $event, int $userId, ?string $justification = null): void
    {
        $note = DB::table($type->table())->find($noteId);

        $this->insert(
            (int) $note->ref_embarque,
            self::SUBJECTS[$event].': '.$type->label(),
            $event,
            $this->history($type, $note),
            $userId,
            $justification,
        );
    }

    /** Troca de "não precisa de nota" na reserva, com a justificativa (executeSaveJustificativa[Debit]). */
    public function status(NoteType $type, int $bookingId, int $userId, string $justification): void
    {
        $date = DB::table('embarque')->where('embarque_id', $bookingId)->value($type->needColumn());
        $set = filled($date) && $date !== '0000-00-00 00:00:00';

        $this->insert(
            $bookingId,
            'Atualizado: Status '.$type->label().' para '.($set ? 'SIM' : 'NÃO'),
            $type->statusEvent(),
            '<h3>Dados</h3> <p>Necessidade de Nota: '.($set ? 'NÃO' : 'SIM').'</p><p>Data: '.($set ? Carbon::parse($date)->format('d/m/Y H:i') : ' ').'</p>',
            $userId,
            $justification,
        );
    }

    private function insert(int $bookingId, string $subject, string $event, string $history, int $userId, ?string $justification): void
    {
        DB::table('rastreamento')->insert([
            'assunto' => $subject,
            'historico' => $history,
            'ref_embarque' => $bookingId,
            'evento' => $event,
            'justificativa' => $justification,
            'created_at' => now()->toDateString(),
            'created_time' => now()->format('H:i:s'),
            'created_by' => $userId,
        ]);
    }

    private function history(NoteType $type, object $note): string
    {
        $booking = DB::table('embarque')->where('embarque_id', $note->ref_embarque)->first();
        $person = fn ($id) => e((string) DB::table('pessoa')->where('pessoa_id', $id)->value('nome'));

        return '<h3>Dados da '.$type->abbreviation().'</h3> '
            ."<p>{$type->label()}: ".e((string) $note->name).'</p>'
            .'<p>Date: '.Carbon::parse($note->created_at)->format('d/m/Y').'</p>'
            .'<p>HBL: '.e((string) $booking->house_bl).'</p>'
            .'<p>Customer Invoice: '.e((string) $booking->invoice).'</p>'
            .'<p>Booking: '.e((string) $booking->booking).'</p>'
            .'<p>Vessel: '.e((string) DB::table('navio')->where('navio_id', $booking->navio_id)->value('nome')).'</p>'
            .'<p>ETD: '.Carbon::parse($booking->saida_efetiva ?: now())->format('d/m/Y').'</p>'
            .'<p>Tx.  Currency: '.e((string) DB::table('cmn_currencies')->where('id', $note->moeda_id)->value('symbol')).' '.number_format((float) $note->dolar_rate, 4, ',', '.').'</p>'
            .'<p>Shipper: '.$person($booking->pessoa_id_cliente).'</p>'
            .'<p>Cnee: '.$person($booking->pessoa_id_importador).'</p>'
            .'<p>TO: '.$person($note->ref_agente).'</p>'
            .'<p>Vendedor: '.e((string) DB::table('vendedor')->where('vendedor_id', $booking->vendedor_id)->value('nome')).'</p>';
    }
}
