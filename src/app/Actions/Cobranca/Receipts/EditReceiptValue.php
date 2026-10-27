<?php

namespace App\Actions\Cobranca\Receipts;

use App\Models\FinCharges;
use DomainException;

/** editValorRecibo (legado): valor do recibo digitado em pt-BR; vazio limpa. */
class EditReceiptValue
{
    public function __invoke(FinCharges $charge, int $receiptId, ?string $value): void
    {
        $receipt = $charge->receipts()->findOrFail($receiptId);
        $value = str_replace(',', '.', str_replace('.', '', trim((string) $value)));

        if ($value !== '' && ! is_numeric($value)) {
            throw new DomainException('Valor do recibo inválido.');
        }

        $receipt->forceFill(['valor' => $value === '' ? null : round((float) $value, 2)])->save();
    }
}
