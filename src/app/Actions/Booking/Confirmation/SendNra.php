<?php

namespace App\Actions\Booking\Confirmation;

use App\Models\Embarque;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Regra NRA (legado): exportação para os EUA (país 4), fechamento na origem (DMF) e sem BL direto leva os documentos "NRA
 * Procedures" (arquivo tipo 44) ao agente. Sem os documentos a saída não confirma; com eles e agente da AIC, o envio sai na
 * primeira confirmação e pode ser repetido pelo botão (`enviaEmailNra`).
 */
class SendNra
{
    private const FILE_TYPE = 44;

    private const AIC_AGENTS = [1838, 3548, 10959, 10960, 10961, 11517];

    public function applies(Embarque $booking): bool
    {
        return $booking->impor_expor === 'e'
            && $booking->tipo_fechamento === 'o'
            && ! $booking->bl_direto
            && (int) DB::table('porto')->where('porto_id', $booking->porto_destino_id)->value('ref_pais') === 4;
    }

    public function isAicAgent(Embarque $booking): bool
    {
        return in_array((int) $booking->pessoa_id_representante, self::AIC_AGENTS, true);
    }

    /** @return Collection<int, string> nomes dos arquivos NRA da reserva */
    public function files(Embarque $booking): Collection
    {
        return DB::table('emb_embarque_files as f')
            ->join('cmn_files as c', 'c.id', '=', 'f.ref_file')
            ->where('f.ref_embarque', $booking->getKey())
            ->where('f.ref_file_type', self::FILE_TYPE)
            ->pluck('c.name');
    }

    public function sendCount(Embarque $booking): int
    {
        return DB::table('embarque_nra')->where('embarque_id', $booking->getKey())->count();
    }

    /** O envio automático da primeira confirmação. */
    public function shouldSendOnConfirmation(Embarque $booking): bool
    {
        return $this->applies($booking) && $this->isAicAgent($booking) && $this->files($booking)->isNotEmpty() && $this->sendCount($booking) === 0;
    }

    public function __invoke(Embarque $booking, int $userId): void
    {
        DB::table('embarque_nra')->insert(['embarque_id' => $booking->getKey(), 'created_at' => now(), 'created_by' => $userId]);

        $to = ['exportacao@dmflog.com.br', ...($this->isAicAgent($booking) ? ['eddiec@shipamerican.com', 'aicil@shipamerican.com'] : [])];
        $shipper = DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_cliente)->value('nome');

        Mail::html('Please find attached the documents for the NRA Procedures', function ($mail) use ($booking, $to, $shipper) {
            $mail->to($to)->subject("DMF - NRA PROCEDURES - BOOKING {$booking->booking} SHIPPER {$shipper}");

            foreach ($this->files($booking) as $name) {
                if (Storage::disk('tracking_uploads')->exists("docs_emb/{$name}")) {
                    $mail->attach(Storage::disk('tracking_uploads')->path("docs_emb/{$name}"), ['as' => $name]);
                }
            }
        });
    }
}
