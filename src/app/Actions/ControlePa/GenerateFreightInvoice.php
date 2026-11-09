<?php

namespace App\Actions\ControlePa;

use App\Models\CmnFile;
use App\Models\ControlePa;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * freightInvoice (legado): gera o PDF da Freight Invoice, guarda em uploads/docs_emb como arquivo de tipo 24 da reserva e abre
 * o controle PA (flag_fi_dmf = 0, "Não enviado"). A tela do legado só oferece a criação com Freight Invoice "Sim", recebedor
 * do pagamento escolhido e sem controle aberto; aqui também se exige pagador ou importador, sem os quais o legado falhava.
 */
class GenerateFreightInvoice
{
    public function __construct(private FreightInvoicePdf $pdf) {}

    /** @throws DomainException */
    public function __invoke(Embarque $booking, int $userId): ControlePa
    {
        if ((int) $booking->flag_fi !== 1) {
            throw new DomainException('A reserva não está marcada com Freight Invoice.');
        }

        if (! array_key_exists((string) $booking->recebedor_freight_invoice, FreightInvoicePdf::RECEIVERS)) {
            throw new DomainException('É necessário selecionar o recebedor do pagamento para gerar o documento!');
        }

        if (! $booking->pessoa_id_pagador && ! $booking->pessoa_id_importador) {
            throw new DomainException('É necessário informar o pagador ou o importador da reserva.');
        }

        if ($booking->controlePa()->exists()) {
            throw new DomainException('A Freight Invoice desta reserva já foi gerada.');
        }

        $name = 'freight_invoice_'.preg_replace('/[^A-Za-z0-9-._]/', '_', (string) $booking->booking).'_'.now()->format('dmyGis').'.pdf';
        $content = $this->pdf->render($booking)->Output('', 'S');

        return DB::transaction(function () use ($booking, $userId, $name, $content) {
            $file = CmnFile::forceCreate([
                'uid' => $booking->getKey(),
                'name' => $name,
                'subject' => 'Documento Anexo a Reserva',
                'created_at' => now()->toDateString(),
                'created_by' => $userId,
            ]);
            $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();

            Storage::disk('tracking_uploads')->put("docs_emb/{$name}", $content);

            EmbEmbarqueFile::forceCreate(['ref_embarque' => $booking->getKey(), 'ref_file' => $file->getKey(), 'ref_file_type' => EmbEmbarqueFile::DMF_INVOICE]);

            return ControlePa::forceCreate(['embarque_id' => $booking->getKey(), 'flag_fi_dmf' => 0]);
        });
    }
}
