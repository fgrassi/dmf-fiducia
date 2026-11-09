<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * executeEnviarDebitNote / executeEnviarCreditNote (legado): manda o último PDF gerado da nota ao financeiro e, na
 * exportação, aos contatos autorizados do agente; marca a nota como enviada (`status` = 1). Para os agentes que recebem
 * em euro (2747, 12485, 12484, 3586) com a nota ainda em dólar, o e-mail vai só ao financeiro pedindo a conversão, sem PDF,
 * e a nota também fica marcada como enviada (como o legado). O débito registra o envio no rastreamento; o crédito, não.
 */
class SendNote
{
    private const EURO_AGENTS = [2747, 12485, 12484, 3586];

    private const FINANCE = 'financeiro@dmflog.com.br';

    private const FINANCE_MANAGER = 'marcia@dmflog.com.br';

    public function __construct(private NoteTracking $tracking) {}

    /** @throws DomainException */
    public function __invoke(NoteType $type, int $noteId, int $userId): void
    {
        $note = DB::table($type->table())->find($noteId);
        $booking = DB::table('embarque')->where('embarque_id', $note->ref_embarque)->first();
        $needsConversion = in_array((int) $note->ref_agente, self::EURO_AGENTS, true) && (int) $note->moeda_id !== ConvertNoteToEuro::EURO;
        $label = strtoupper($type->label());
        $shipment = ' '.$note->name.' - VESSEL '.$this->vessel($booking).' - HBL '.$booking->house_bl.' - BOOKING '.$booking->booking;

        if ($needsConversion) {
            $subject = "DMF Logistics: CONVERSÃO DE {$label}{$shipment}";
            $recipients = [self::FINANCE_MANAGER, self::FINANCE];
            $attachment = null;
            $html = '<p>A nota gerada para este embarque precisa ser convertida para EURO</p>';
        } else {
            $attachment = $this->attachment($type, $note);
            $subject = "DMF Logistics: {$label}{$shipment}";
            $recipients = [...($type === NoteType::Credit ? [self::FINANCE_MANAGER] : []), self::FINANCE];
            $html = view('mail.booking.note', ['title' => $type->label(), 'rows' => $this->rows($booking)])->render();
        }

        $contacts = $needsConversion || $booking->impor_expor !== 'e'
            ? []
            : DB::table('agente_contato')
                ->where('ref_pessoa', $note->ref_agente)
                ->where('autorizado', 1)
                ->whereIn('operacao', ['e', 'a'])
                ->get(['name', 'email'])
                ->pluck('name', 'email')
                ->all();

        Mail::html($html, function ($mail) use ($subject, $recipients, $contacts, $attachment) {
            $mail->from('system@dmflog.com.br', 'DMF Logistics')->subject($subject);

            foreach ($recipients as $email) {
                $mail->to($email, 'Setor Financeiro');
            }

            foreach ($contacts as $email => $name) {
                $mail->to($email, $name);
            }

            if ($attachment) {
                $mail->attach($attachment['path'], ['as' => $attachment['name']]);
            }
        });

        DB::transaction(function () use ($type, $noteId, $userId) {
            if ($type === NoteType::Debit) {
                $this->tracking->note($type, $noteId, 'e', $userId);
            }

            DB::table($type->table())->where('id', $noteId)->update(['status' => 1]);
        });
    }

    /** @return array{path: string, name: string} @throws DomainException */
    private function attachment(NoteType $type, object $note): array
    {
        $name = $note->ref_file ? (string) DB::table('cmn_files')->where('id', $note->ref_file)->value('name') : '';
        $disk = Storage::disk('tracking_uploads');
        $path = GenerateNotePdf::path($type, $name);

        if ($name === '' || ! $disk->exists($path)) {
            throw new DomainException('Gere o PDF da nota antes de enviar.');
        }

        return ['path' => $disk->path($path), 'name' => "{$name}.pdf"];
    }

    private function vessel(object $booking): string
    {
        return (string) ($booking->navio_id ? DB::table('navio')->where('navio_id', $booking->navio_id)->value('nome') : '');
    }

    /** @return list<array{0: string, 1: string}> */
    private function rows(object $booking): array
    {
        $person = fn ($id) => (string) DB::table('pessoa')->where('pessoa_id', $id)->value('nome');
        $port = fn ($id) => ($row = $id ? DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')->where('p.porto_id', $id)->first(['p.nome', 'c.nome as country']) : null)
            ? "{$row->nome} ({$row->country})"
            : '';
        $date = fn (?string $value) => filled($value) ? Carbon::parse($value)->format('M-d-Y') : '';

        return [
            ['ETD: ', $date($booking->saida_efetiva)],
            ['ETA: ', $date($booking->chegada_prevista)],
            ['Vessel:', $this->vessel($booking)],
            ['Ss Line: ', (string) DB::table('armador')->where('armador_id', $booking->armador_id)->value('nome')],
            ['Shipper:', $person($booking->pessoa_id_cliente)],
            ['C/nee: ', $person($booking->pessoa_id_importador)],
            ['Port of Loading: ', $port($booking->porto_origem_id)],
            ['Port of Discharge: ', $port($booking->porto_destino_id)],
            ['HBL: ', (string) $booking->house_bl],
            ['MBL: ', (string) $booking->master_bl],
            ['Freight: ', (string) DB::table('incoterm')->where('incoterm_id', $booking->incoterm_id)->value('nome')],
        ];
    }
}
