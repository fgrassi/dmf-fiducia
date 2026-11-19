<?php

namespace App\Actions\Booking\Ams;

use App\Actions\Notices\BookingTags;
use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\AmsDestination;
use App\Enums\Notice;
use App\Models\Embarque;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Avisos de AMS do módulo cron do legado. Os quatro avisos internos listam as exportações marítimas não canceladas e sem BL direto
 * (avisoAms, avisoAmsMexico: EUA e México sem código de aceite no BL com ETD em até 7 dias; avisoAmsAfrica, avisoAmsUae: registro
 * ainda não enviado ao agente com ETD em até 5 dias). Os lembretes (enviaReminderAmsAfrica, enviaReminderAmsUae) reenviam, um
 * e-mail por reserva e em inglês, o registro já enviado e ainda não confirmado, com os HBLs em anexo e os dias desde o envio.
 */
class AmsNotices
{
    private const SYSTEM = 'system@dmflog.com.br';

    private const DRAFT = 'draft@dmflog.com.br';

    private const US = 4;

    private const MEXICO = 17;

    /** @return int reservas listadas */
    public function missingUs(): int
    {
        return $this->missingAms(Notice::AmsMissingUs, self::US);
    }

    /** @return int reservas listadas */
    public function missingMexico(): int
    {
        return $this->missingAms(Notice::AmsMissingMexico, self::MEXICO);
    }

    /** @return int reservas listadas */
    public function pendingAfrica(): int
    {
        return $this->pendingDocs(Notice::AmsPendingAfrica, AmsDestination::Africa);
    }

    /** @return int reservas listadas */
    public function pendingUae(): int
    {
        return $this->pendingDocs(Notice::AmsPendingUae, AmsDestination::Uae);
    }

    /** @return int lembretes enviados */
    public function reminderAfrica(): int
    {
        return $this->reminders(Notice::AmsReminderAfrica, AmsDestination::Africa);
    }

    /** @return int lembretes enviados */
    public function reminderUae(): int
    {
        return $this->reminders(Notice::AmsReminderUae, AmsDestination::Uae);
    }

    private function missingAms(Notice $notice, int $country): int
    {
        if (! $notice->enabled()) {
            return 0;
        }

        $bookings = $this->base($country)
            ->whereBetween('saida_prevista', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->where(fn (Builder $q) => $q->whereDoesntHave('bls')->orWhereHas('bls', fn (Builder $bl) => $bl->where(fn (Builder $b) => $b->whereNull('codigo_aceite')->orWhere('codigo_aceite', ''))))
            ->get();

        return $this->list($notice, $bookings, ['Reserva', 'Cliente', 'ETD']);
    }

    private function pendingDocs(Notice $notice, AmsDestination $destination): int
    {
        if (! $notice->enabled()) {
            return 0;
        }

        $bookings = $this->base($destination->country())
            ->where($destination->statusColumn(), 1)
            ->whereBetween('saida_prevista', [today()->toDateString(), today()->addDays(5)->toDateString()])
            ->get();

        return $this->list($notice, $bookings, ['Reserva', 'Cliente', 'Agente', 'ETD']);
    }

    private function reminders(Notice $notice, AmsDestination $destination): int
    {
        if (! $notice->enabled()) {
            return 0;
        }

        $sent = 0;

        $bookings = $this->base($destination->country())
            ->where($destination->statusColumn(), SendAms::SENT)
            ->whereHas($destination->relation(), fn (Builder $q) => $q->whereNull('data_confirmacao'))
            ->get();

        foreach ($bookings as $booking) {
            $relation = $booking->{$destination->relation()}();
            $registration = $relation->orderBy($relation->getRelated()->getKeyName())->first();
            $files = SendAms::hblFiles($booking)->mapWithKeys(fn (string $name) => [Storage::disk('tracking_uploads')->path("docs_emb/{$name}") => $name])->all();
            $contacts = collect(SendAms::agentContacts($booking))->pluck('email')
                ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();

            [$subject, $html] = app(RenderNoticeEmail::class)($notice, [
                ...app(BookingTags::class)($booking->getKey()),
                'dias' => $registration?->data_envio ? (int) $registration->data_envio->diffInDays(now(), true) : 0,
                'dados' => SendAms::details($booking),
            ]);

            app(SendNoticeMail::class)($subject, $html, $contacts, cc: $notice->copies(), from: self::SYSTEM, fromName: 'DMF Logistics', replyTo: self::DRAFT, attachments: $files) && $sent++;
        }

        return $sent;
    }

    /** Exportações marítimas não canceladas, sem BL direto, para o destino. */
    private function base(int $country): Builder
    {
        return Embarque::query()
            ->with(['cliente', 'agente'])
            ->where('impor_expor', 'e')
            ->where('tipo_embarque', '<>', 'a')
            ->where('bl_direto', 0)
            ->where('finalizado', '<>', 'c')
            ->whereIn('porto_destino_id', DB::table('porto')->where('ref_pais', $country)->select('porto_id'))
            ->orderBy('embarque_id');
    }

    /**
     * @param  iterable<Embarque>  $bookings
     * @param  list<string>  $columns
     */
    private function list(Notice $notice, iterable $bookings, array $columns): int
    {
        $rows = collect($bookings)->map(fn (Embarque $b) => '<tr><td>'.e($b->booking).'</td><td>'.e($b->cliente ? "{$b->cliente->nome} ({$b->cliente->cnpj})" : '').'</td>'
            .(in_array('Agente', $columns, true) ? '<td>'.e((string) $b->agente?->nome).'</td>' : '')
            .'<td>'.($b->saida_prevista ? date('d/m/Y', strtotime($b->saida_prevista)) : '').'</td></tr>');

        if ($rows->isEmpty()) {
            return 0;
        }

        $list = '<table border="1"><tr>'.collect($columns)->map(fn (string $c) => "<th>{$c}</th>")->implode('').'</tr>'.$rows->implode('').'</table>';

        [$subject, $html] = app(RenderNoticeEmail::class)($notice, ['lista' => $list]);

        app(SendNoticeMail::class)($subject, $html, $notice->recipients());

        return $rows->count();
    }
}
