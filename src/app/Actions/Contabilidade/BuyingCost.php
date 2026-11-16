<?php

namespace App\Actions\Contabilidade;

/** Custo de compra de uma despesa da reserva (buying × fator da unidade, com o mínimo br_min), como o legado calcula. */
class BuyingCost
{
    /** Fator pela unidade da despesa: 1 = por BL (qtd_bl), 2 = por container (soma da mercadoria), demais = 1. */
    public const FACTOR_SQL = 'CASE
        WHEN fe.ref_unity = 1 THEN COALESCE(NULLIF(e.qtd_bl, 0), 1)
        WHEN fe.ref_unity = 2 THEN COALESCE((
            SELECT SUM(m.quantidade) FROM mercadoria m
            WHERE m.embarque_id = e.embarque_id
              AND (fev.ref_equipment IS NULL OR fev.ref_equipment = 0 OR m.tipo_container_id = fev.ref_equipment)
        ), 0)
        ELSE 1
    END';

    /** @param  object  $row  com buying_rate, br_min e fator */
    public static function value(object $row): float
    {
        $factor = (float) $row->fator > 0 ? (float) $row->fator : 1.0;
        $value = (float) $row->buying_rate * $factor;
        $min = (float) $row->br_min;

        return $min > 0 && $value < $min ? $min : $value;
    }
}
