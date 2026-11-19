<?php

namespace App\Actions\Booking\Ams;

use App\Actions\Booking\Bls\FinalizeBl;
use App\Enums\AmsDestination;
use App\Models\Embarque;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * enviarAmsAfrica / enviarAmsUae (legado): gera o PDF de cada BL e manda ao agente do destino (contatos do porto, mais a
 * Documentação) o registro do embarque com as cópias do HBL em anexo; a reserva passa a "enviado, pendente de
 * confirmação" e o envio fica registrado. Diferença do legado: o status só muda depois que o e-mail sai, então um erro de envio
 * deixa o envio para ser repetido.
 */
class SendAms
{
    private const SYSTEM = 'system@dmflog.com.br';

    private const DRAFT = 'draft@dmflog.com.br';

    /** Tipo do documento da reserva com o PDF do HBL. */
    private const HBL_FILE_TYPE = 1;

    public const SENT = 2;

    public const INTRO = '<p>Please note HBL copy and MBL number regarding shipment below. If you require further assistance please email to draft@dmflog.com.br and exportacao@dmflog.com.br</p>'
        .'<p>It is important and mandatory that you confirm the receipt of this message</p>';

    public function __construct(private FinalizeBl $finalize) {}

    public function __invoke(AmsDestination $destination, Embarque $booking, int $userId): void
    {
        $bls = $booking->bls()->get();

        foreach ($bls as $bl) {
            $this->finalize->store($booking, $bl);
        }

        $this->deliver($destination, $booking, $this->contacts($booking));

        DB::transaction(function () use ($destination, $booking, $userId) {
            $booking->forceFill([$destination->statusColumn() => self::SENT])->save();

            $registration = $booking->{$destination->relation()}()->first()
                ?? $destination->model()::make(['embarque_id' => $booking->getKey(), 'created_at' => now(), 'created_by' => $userId]);
            $registration->forceFill(['data_envio' => now()])->save();
        });
    }

    /** Contatos do agente da reserva para o porto de destino (ou sem porto definido). */
    public static function agentContacts(Embarque $booking): array
    {
        if (! $booking->pessoa_id_representante) {
            return [];
        }

        return DB::table('agente_contato')
            ->where('ref_pessoa', $booking->pessoa_id_representante)
            ->where(fn ($query) => $query->where('porto_id', $booking->porto_destino_id)->orWhereNull('porto_id'))
            ->get(['name', 'email'])
            ->map(fn ($contact) => ['name' => (string) $contact->name, 'email' => (string) $contact->email])
            ->all();
    }

    /** Nomes dos PDFs do HBL da reserva que existem em disco (docs_emb). */
    public static function hblFiles(Embarque $booking): Collection
    {
        return DB::table('emb_embarque_files as x')->join('cmn_files as f', 'f.id', '=', 'x.ref_file')
            ->where('x.ref_embarque', $booking->getKey())->where('x.ref_file_type', self::HBL_FILE_TYPE)->pluck('f.name')
            ->filter(fn (string $name) => Storage::disk('tracking_uploads')->exists("docs_emb/{$name}"));
    }

    /** @return list<string> */
    private function contacts(Embarque $booking): array
    {
        return collect(self::agentContacts($booking))->pluck('email')->push(self::DRAFT)
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
    }

    /** @param list<string> $to */
    private function deliver(AmsDestination $destination, Embarque $booking, array $to): void
    {
        $files = self::hblFiles($booking);

        Mail::html($this->body($booking), function ($mail) use ($destination, $booking, $to, $files) {
            $mail->from(self::SYSTEM, 'DMF Logistics')
                ->to($to)
                ->replyTo(self::DRAFT, 'DMF Draft Team')
                ->subject("DMF Brazil - Shipment Registration to {$destination->destinationName()}: {$booking->booking} - {$booking->house_bl}");

            foreach ($files as $name) {
                $mail->attach(Storage::disk('tracking_uploads')->path("docs_emb/{$name}"), ['as' => $name]);
            }
        });
    }

    private function body(Embarque $booking): string
    {
        return self::INTRO.self::details($booking);
    }

    /** Tabela com os dados do embarque e os containers (também usada no lembrete ao agente). */
    public static function details(Embarque $booking): string
    {
        $date = fn (?string $value) => filled($value) ? date('d/m/Y', strtotime($value)) : '';
        $rows = [
            'Shipper' => $booking->exportador?->nome,
            'Consignee' => $booking->importador?->nome,
            'Booking' => $booking->booking,
            'House BL' => $booking->house_bl,
            'Master BL' => $booking->master_bl,
            'Invoice' => $booking->invoice,
            'Port of Origin' => $booking->portoOrigem?->nome,
            'Port of Destination' => $booking->portoDestino?->nome,
            'SS Line' => $booking->armador?->nome,
            'Vessel / VG' => $booking->navio?->nome.'/'.$booking->vg,
            'ETD' => $date($booking->saida_prevista),
            'ETA' => $date($booking->chegada_prevista),
        ];
        $optional = ['Invoice', 'Port of Origin', 'Port of Destination'];
        $table = collect($rows)->reject(fn ($value, $label) => in_array($label, $optional, true) && blank($value))
            ->map(fn ($value, $label) => '<tr><th align="left">'.e($label).'</th><td>'.e((string) $value).'</td></tr>')->implode('');

        $containers = DB::table('container as c')->join('bl', 'bl.bl_id', '=', 'c.bl_id')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')
            ->where('bl.embarque_id', $booking->getKey())->orderBy('c.container_id')->get(['c.numero', 't.nome as type'])
            ->map(fn ($c) => '<tr><td>'.e((string) $c->numero).'</td><td>'.e((string) $c->type).'</td></tr>')->implode('');

        return '<table>'.$table.'<tr><th align="left">Containers</th></tr>'.$containers.'</table>';
    }
}
