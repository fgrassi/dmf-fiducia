<?php

namespace App\Actions\ControlePa;

use App\Models\EmbEmbarqueFile;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * enviaFI (legado): manda a Freight Invoice para Exportação e Financeiro e marca o controle PA como enviado (flag_fi_dmf = 1).
 * O legado marcava sem conferir o estado; aqui só se envia o que ainda está "Não enviado", para não rebaixar "Embarque Confirmado".
 */
class SendFreightInvoice
{
    private const RECIPIENTS = ['exportacao@dmflog.com.br', 'financeiro@dmflog.com.br'];

    /** @throws DomainException */
    public function __invoke(EmbEmbarqueFile $link, string $userName, ?string $userEmail): void
    {
        abort_unless((int) $link->ref_file_type === EmbEmbarqueFile::DMF_INVOICE, 404);

        $booking = $link->embarque;
        $control = $booking?->controlePa;

        if (! $control || (int) $control->flag_fi_dmf !== 0) {
            throw new DomainException('A Freight Invoice já foi enviada ou não tem controle PA aberto.');
        }

        $name = (string) $link->file?->name;
        $path = Storage::disk('tracking_uploads')->path("docs_emb/{$name}");

        if ($name === '' || ! is_file($path)) {
            throw new DomainException('O arquivo da Freight Invoice não foi encontrado.');
        }

        $client = (string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_cliente)->value('nome');

        Mail::html(
            '<p>Segue em anexo a Freight Invoice referente a reserva '.e($booking->booking).', cliente '.e($client).'</p>',
            function ($mail) use ($booking, $userName, $userEmail, $name, $path) {
                $mail->from('no-reply@dmflog.com.br', "{$userName} - DMF Logistics do Brasil")
                    ->to(self::RECIPIENTS)
                    ->subject("[PREPAID ABROAD] Freight Invoice {$booking->booking}")
                    ->attach($path, ['as' => $name]);

                if ($userEmail) {
                    $mail->replyTo($userEmail, $userName);
                }
            },
        );

        $control->forceFill(['flag_fi_dmf' => 1])->save();
    }
}
