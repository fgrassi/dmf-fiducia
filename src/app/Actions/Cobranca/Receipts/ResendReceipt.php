<?php

namespace App\Actions\Cobranca\Receipts;

use App\Models\FinCharges;
use DomainException;

/** reenviaRecibo (legado): reenvia o PDF de um recibo ao setor de cobrança e aos contatos do cliente. */
class ResendReceipt
{
    public function __construct(private SendReceipts $send) {}

    public function __invoke(FinCharges $charge, int $receiptId): void
    {
        $name = $charge->receipts()->with('file')->findOrFail($receiptId)->file?->name
            ?? throw new DomainException('O arquivo do recibo não foi encontrado.');

        ($this->send)($charge, ["{$name}.pdf"], resend: true);
    }
}
