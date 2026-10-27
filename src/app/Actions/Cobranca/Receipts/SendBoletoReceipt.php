<?php

namespace App\Actions\Cobranca\Receipts;

use App\Actions\Cobranca\Documents\ReceiptPdf;
use App\Models\CmnFile;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * enviaReciboBoleto → Cobranca::geraReciboBoleto (legado; é o que o botão "Enviar declaração" chama):
 * gera um novo Recibo do Boleto (recibo_boleto + cmn_files), grava o PDF em uploads/recibo
 * e envia para cobranca@ e os contatos do cliente.
 */
class SendBoletoReceipt
{
    public function __construct(
        private ReceiptPdf $pdf,
        private SendReceipts $send,
    ) {}

    /** @return string arquivo gerado em uploads/recibo */
    public function __invoke(FinCharges $charge): string
    {
        // recibo_boleto é MyISAM: sem transação, como no legado
        $id = DB::table('recibo_boleto')->insertGetId(['cobranca_id' => $charge->id]);
        $file = CmnFile::forceCreate([
            'name' => 'recibo_dmf'.now()->format('Y').$id,
            'subject' => "Recibo do Booking {$charge->embarque->booking}",
            'created_at' => now()->toDateString(),
        ]);
        $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();
        DB::table('recibo_boleto')->where('recibo_boleto_id', $id)->update(['arquivo_id' => $file->getKey()]);

        [$pdf] = $this->pdf->render($charge, $id, boleto: true);
        Storage::disk('tracking_uploads')->put("recibo/{$file->name}.pdf", $pdf->Output('', 'S'));

        ($this->send)($charge, ["{$file->name}.pdf"]);

        return "{$file->name}.pdf";
    }
}
