<?php

namespace App\Enums\Cobranca;

/** fin_charges.status */
enum ChargeStatus: int
{
    case Open = 0;
    case Paid = 1;
    case Boleto = 2;
    case ManualSettlement = 3;

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberto',
            self::Paid => 'Pago',
            self::Boleto => 'Boleto',
            self::ManualSettlement => 'Baixa Manual',
        };
    }

    public function isSettled(): bool
    {
        return $this !== self::Open;
    }
}
