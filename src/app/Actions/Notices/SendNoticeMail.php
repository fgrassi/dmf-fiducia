<?php

namespace App\Actions\Notices;

use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envia o e-mail de um aviso. Uma falha de envio (endereço inválido, SMTP fora) vai para o log da aplicação e não interrompe os envios
 * seguintes da mesma rotina.
 */
class SendNoticeMail
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  array<string, string>  $attachments  caminho do arquivo => nome no e-mail
     * @return bool false se o envio falhou
     */
    public function __invoke(string $subject, string $html, array $to, array $cc = [], array $bcc = [], ?string $from = null, ?string $fromName = null, ?string $replyTo = null, array $attachments = []): bool
    {
        try {
            Mail::html($html, function ($mail) use ($subject, $to, $cc, $bcc, $from, $fromName, $replyTo, $attachments) {
                $from && $mail->from($from, $fromName);
                $mail->to($to);
                $cc && $mail->cc($cc);
                $bcc && $mail->bcc($bcc);
                $replyTo && $mail->replyTo($replyTo);
                foreach ($attachments as $path => $name) {
                    $mail->attach($path, ['as' => $name]);
                }
                $mail->subject($subject);
            });
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
