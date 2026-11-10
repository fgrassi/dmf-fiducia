<?php

namespace App\Actions\Courier;

use App\Models\TipoCourier;

/** tipoCourier com `add` (legado): cria o tipo na hora, a partir do campo do courier. */
class CreateCourierType
{
    public function __invoke(string $name): TipoCourier
    {
        return TipoCourier::create(['nome' => trim($name)]);
    }
}
