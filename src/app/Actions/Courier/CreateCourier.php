<?php

namespace App\Actions\Courier;

use App\Models\ControleCourier;

/** saveCourier (legado): lança o courier. A tela do legado só o usa para criar. */
class CreateCourier
{
    /** @param  array{numero:string, nota:?string, data_recebimento:string, tipo_courier_id:int, conta_utilizada:string}  $data */
    public function __invoke(array $data): ControleCourier
    {
        return ControleCourier::create($data);
    }
}
