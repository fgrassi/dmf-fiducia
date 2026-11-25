<?php

namespace App\Actions\Booking\Tracking;

use App\Models\Embarque;
use App\Models\FraseTransborda;
use App\Models\Transborda;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * updateRastreamento / duplicarTracking (legado): manda a mensagem de rastreamento por e-mail aos contatos da reserva.
 * Na importação vai uma mensagem só, com todos os contatos e cópia oculta para trackingimp@; na exportação, uma por tipo de
 * contato, com cópia oculta para tracking@, e Consignee, Notify e Agente ficam sem a saudação. O cliente com `histtxt`
 * recebe em texto puro. Frase 78 com container NOR leva o comunicado de NOR; frases 27, 29, 78 e 80 na importação levam
 * o comunicado de divergência de peso (se o arquivo existir). Diferença do legado: sem o PDF do histórico (`histpdf`) nem
 * o comunicado da MSC na duplicação, e nenhum erro de envio interrompe a gravação. O e-mail do contato perde os espaços das
 * pontas (o PHPMailer do legado aceitava, o filtro não).
 */
class SendTracking
{
    private const SYSTEM = 'system@dmflog.com.br';

    private const NOR_CONTAINER = 23;

    private const NOR_PHRASE = 78;

    private const DIVERGENCE_PHRASES = [27, 29, 78, 80];

    /** Tipos de contato da exportação que não recebem a saudação. */
    private const WITHOUT_GREETING = ['Consignee', 'Notify', 'Agente'];

    public function __construct(private TrackingRecipients $recipients, private BuildTrackingMessage $message, private TrackingGreeting $greeting) {}

    /**
     * @param  list<string>  $extraAttachments  caminhos no disco `tracking_uploads`, além dos avisos de NOR e divergência
     * @param  list<string>  $cc  cópia de todas as mensagens
     * @return bool false se não havia contato ou algum envio falhou
     */
    public function __invoke(Embarque $booking, Transborda $tracking, int $userId, array $extraAttachments = [], array $cc = []): bool
    {
        $contacts = collect(($this->recipients)($booking))
            ->map(fn (array $contact) => ['email' => trim((string) $contact['email'])] + $contact)
            ->filter(fn (array $contact) => filter_var($contact['email'], FILTER_VALIDATE_EMAIL));

        if ($contacts->isEmpty()) {
            return false;
        }

        $import = $booking->impor_expor === 'i';
        $phraseId = (int) $tracking->ref_frase_transborda;
        $nor = $phraseId === self::NOR_PHRASE && DB::table('mercadoria')->where('embarque_id', $booking->getKey())->where('tipo_container_id', self::NOR_CONTAINER)->exists();
        $plain = DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('histtxt') === 's';
        $message = ($this->message)(
            $booking,
            (string) $tracking->descr,
            $tracking->data_reg->format('d/m/Y'),
            (string) FraseTransborda::query()->whereKey($phraseId)->value('valor'),
            $plain,
            $nor ? "<br/><p style='color:red'><strong>Gentileza notar em anexo instruções a respeito do transporte de mercadorias secas em container NOR</strong></p>" : '',
        );
        $attachments = array_filter([
            $nor ? 'tmp/transporte_mercadoria_nor.pdf' : null,
            $import && in_array($phraseId, self::DIVERGENCE_PHRASES, true) ? 'mensagens/IMPORTANTE_COMUNICADO_SOBRE_DIVERGENCIA_DE_PESO_NO_BL.pdf' : null,
            ...$extraAttachments,
        ], fn (?string $path) => $path && Storage::disk('tracking_uploads')->exists($path));

        $groups = $import ? ['' => $contacts] : $contacts->groupBy('tipo');
        $sent = true;

        foreach ($groups as $type => $group) {
            $greeting = $plain || (! $import && in_array($type, self::WITHOUT_GREETING, true)) ? '' : ($this->greeting)($userId);

            try {
                $this->deliver($booking, $group->pluck('email')->unique()->all(), $message, $greeting, $attachments, $cc);
            } catch (Throwable $e) {
                report($e);
                $sent = false;
            }
        }

        return $sent;
    }

    /**
     * @param  list<string>  $to
     * @param  array{subject: string, body: string, html: bool}  $message
     * @param  list<string>  $attachments
     * @param  list<string>  $cc
     */
    private function deliver(Embarque $booking, array $to, array $message, string $greeting, array $attachments, array $cc = []): void
    {
        $import = $booking->impor_expor === 'i';
        [$from, $fromName] = $this->sender($booking->impor_expor);

        $configure = function ($mail) use ($to, $message, $attachments, $cc, $import, $from, $fromName) {
            $mail->from(self::SYSTEM, 'DMF Logistics')->to($to)->subject($message['subject']);

            if ($cc) {
                $mail->cc($cc);
            }

            if ($from && $from !== self::SYSTEM) {
                $mail->replyTo($from, $fromName)->replyTo('tracking@dmflog.com.br', 'DMF Tracking Team');
            }

            if ($import) {
                $mail->bcc('trackingimp@dmflog.com.br', 'Tracking Imp')->replyTo('docimportacao@dmflog.com.br', 'DMF Doc Team');
            } else {
                $mail->bcc('tracking@dmflog.com.br', 'Tracking Exp');
            }

            foreach ($attachments as $path) {
                $mail->attach(Storage::disk('tracking_uploads')->path($path), ['as' => basename($path)]);
            }
        };

        $message['html']
            ? Mail::html($greeting.$message['body'], $configure)
            : Mail::raw($message['body'], $configure);
    }

    /**
     * Remetente de resposta do tipo de reserva (configuracoes: rastreamento_impo, rastreamento_expo ou rastreamento_from).
     *
     * @return array{0: ?string, 1: string}
     */
    private function sender(?string $operation): array
    {
        [$key, $name] = match ($operation) {
            'i' => ['rastreamento_impo', 'DMF Import Team'],
            'e' => ['rastreamento_expo', 'DMF Export Team'],
            default => ['rastreamento_from', 'DMF Logistics'],
        };

        return [DB::table('configuracoes')->where('identificacao', $key)->value('valor'), $name];
    }
}
