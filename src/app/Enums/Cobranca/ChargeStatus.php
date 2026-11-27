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

    /** Baixa bancária e manual valem para a IC em aberto ou para trocar a baixa por boleto. */
    public function allowsBankOrManualSettlement(): bool
    {
        return in_array($this, [self::Open, self::Boleto], true);
    }

    public function isSettled(): bool
    {
        return $this !== self::Open;
    }
}
