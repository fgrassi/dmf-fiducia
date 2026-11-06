<?php

namespace App\Actions\Comissoes;

use DomainException;
use Illuminate\Support\Facades\DB;

/** executeUpdateTipo (comissoes, legado): troca "Tipo" (pagar/receber) ou "Tipo de comissão" (processo/container/BL) da comissão. */
class ChangeCommissionType
{
    /** @throws DomainException campo ou valor fora das opções */
    public function __invoke(int $commissionId, string $field, int|string $value): void
    {
        $options = ['tipo' => ShippingCommissionsReport::DIRECTIONS, 'tipo_comissao' => ShippingCommissionsReport::COMMISSION_TYPES];

        if (! isset($options[$field]) || ! array_key_exists((int) $value, $options[$field]) || (string) (int) $value !== (string) $value) {
            throw new DomainException('Valor inválido.');
        }

        DB::table('comissao_embarque')->where('comissao_embarque_id', $commissionId)->update([$field => (int) $value]);
    }
}
