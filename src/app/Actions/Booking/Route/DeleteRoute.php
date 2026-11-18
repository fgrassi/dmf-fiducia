<?php

namespace App\Actions\Booking\Route;

use App\Models\EmbarqueRota;
use App\Models\Transborda;
use Illuminate\Support\Facades\DB;

/** removeRota (legado): apaga a rota e as mensagens de rastreamento que ela lançou. */
class DeleteRoute
{
    public function __invoke(EmbarqueRota $route): void
    {
        DB::transaction(function () use ($route) {
            Transborda::where('rota_id', $route->getKey())->delete();
            $route->delete();
        });
    }
}
