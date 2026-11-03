<?php

namespace App\Actions\BoxControl;

use App\Models\ControleCaixa;
use Illuminate\Support\Facades\DB;

/**
 * removeNome (legado): apaga a caixa e os vínculos com as reservas.
 *
 * Igual ao legado, as reservas continuam com `finalizado = 'e'`: o valor anterior não fica guardado.
 */
class DeleteBox
{
    public function __invoke(ControleCaixa $box): void
    {
        DB::transaction(function () use ($box) {
            $box->bookings()->delete();
            $box->delete();
        });
    }
}
