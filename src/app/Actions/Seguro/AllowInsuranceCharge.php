<?php

namespace App\Actions\Seguro;

use App\Models\Embarque;
use App\Models\InsuranceConfirmation;
use DomainException;

/** seguro/confirmaFinanceiro (legado), "Permitir cobrança": habilita o seguro da reserva para a geração da cobrança. */
class AllowInsuranceCharge
{
    public function __invoke(Embarque $booking): void
    {
        $confirmation = $booking->insuranceConfirmation;

        if (! $confirmation) {
            throw new DomainException('A reserva não tem seguro para habilitar.');
        }

        $confirmation->update(['control' => InsuranceConfirmation::CHARGE_ALLOWED]);
    }
}
