<?php

namespace App\Actions\Courier;

use App\Models\ControleCourier;
use App\Models\CtrCourierSemEmbarque;
use DomainException;

/**
 * incluirSemEmbarque (legado): liga ao courier um nome livre, para o courier que não é de nenhuma reserva.
 *
 * @throws DomainException
 */
class AddCourierName
{
    public function __invoke(ControleCourier $courier, string $name): CtrCourierSemEmbarque
    {
        throw_if($courier->isLinked(), DomainException::class, 'Courier com nota vinculada não aceita novos nomes.');

        return $courier->names()->create(['nome' => trim($name)]);
    }
}
