<?php

namespace App\Actions\PaymentOrders;

use App\Models\EmbEmbarqueFile;

/**
 * deleteComprovante (legado): desliga o comprovante da reserva. Só apaga o vínculo: o arquivo e o cadastro dele ficam, e a
 * ordem continua paga. O legado aceitava qualquer vínculo; aqui só os de tipo comprovante (a tela só oferece esses).
 */
class DeletePaymentReceipt
{
    public function __invoke(EmbEmbarqueFile $link): void
    {
        abort_unless((int) $link->ref_file_type === EmbEmbarqueFile::PAYMENT_RECEIPT, 404);

        $link->delete();
    }
}
