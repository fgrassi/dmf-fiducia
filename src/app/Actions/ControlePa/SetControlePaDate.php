<?php

namespace App\Actions\ControlePa;

use App\Models\ControlePa;
use Carbon\CarbonImmutable;
use DomainException;
use Throwable;

/** editDataEnvioFiAgente / editDataPagamentoArmador / editDataReciboArmador (legado): grava a data da etapa, que só abre depois da anterior. */
class SetControlePaDate
{
    /** @throws DomainException */
    public function __invoke(ControlePa $row, string $field, string $date): void
    {
        if (! $row->canSetDate($field)) {
            throw new DomainException('Esta etapa ainda não foi liberada.');
        }

        try {
            $day = CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            throw new DomainException('Data inválida.');
        }

        $row->forceFill([$field => $day])->save();
    }
}
