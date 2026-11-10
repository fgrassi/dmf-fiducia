<?php

namespace App\Actions\Courier;

use Illuminate\Support\Facades\DB;

/**
 * getSequencia (legado): "Solicitar número" da nota. Grava o próximo da sequência e o devolve; cada pedido gasta um número,
 * mesmo que a janela seja cancelada em seguida (igual ao legado).
 */
class NextCourierNumber
{
    public function __invoke(): int
    {
        return DB::transaction(function () {
            $next = (int) DB::table('sequencia_courrier')->lockForUpdate()->max('sequencia') + 1;

            DB::table('sequencia_courrier')->insert(['sequencia' => $next]);

            return $next;
        });
    }
}
