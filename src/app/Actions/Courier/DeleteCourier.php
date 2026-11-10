<?php

namespace App\Actions\Courier;

use App\Models\ControleCourier;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * remove (legado): apaga o courier com os embarques e os nomes vinculados.
 *
 * Diferença do legado: ele só escondia o botão do courier com nota vinculada; aqui a ação recusa, porque a despesa do
 * agente já nasceu dele (para soltar, remova a despesa em "Notas Variadas Agentes"). Tudo na mesma transação.
 *
 * @throws DomainException
 */
class DeleteCourier
{
    public function __invoke(ControleCourier $courier): void
    {
        if ($courier->isLinked()) {
            throw new DomainException('Courier com nota vinculada não pode ser removido.');
        }

        DB::transaction(function () use ($courier) {
            DB::table('ctr_courier_embarque')->where('controle_courier_id', $courier->getKey())->delete();
            DB::table('ctr_courier_sem_embarque')->where('controle_courier_id', $courier->getKey())->delete();
            $courier->delete();
        });
    }
}
