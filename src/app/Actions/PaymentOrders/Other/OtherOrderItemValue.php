<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\Embarque;
use App\Services\Cobranca\ChargeDetails;
use Illuminate\Support\Facades\DB;

/**
 * calculaDebito, indice e calculaValorUnitarioOrdem (legado, ordemPagamentoOutros): valor em reais de um item da ordem a
 * despachante, cia aérea ou seguro. Diferente da ordem do armador: usa a quantidade de BLs e de equipamentos da reserva
 * (o "índice"), a taxa digitada pelo usuário e o valor mínimo do cadastro da despesa.
 */
class OtherOrderItemValue
{
    /** Despesa de desconto: sempre negativa. */
    private const DISCOUNT_EXPENSE = 1482;

    private const UNIT_BL = 1;

    private const UNIT_CONTAINER = 2;

    private const UNIT_WEIGHT_MEASURE = 3;

    private const UNIT_PROCESS = 5;

    private const REAL = 1;

    /** Despesa-frete => despesas de comissão somadas ao valor unitário. */
    private const COMMISSIONS = [1 => [33, 1048], 41 => [419], 200 => [356], 73 => [387], 273 => [387]];

    public function __construct(private ChargeDetails $factors) {}

    /** @param  object  $expense  linha de fin_expenses_values */
    public function debit(object $expense, float $rate, float $unit, Embarque $booking): float
    {
        if ((int) $expense->ref_expense === self::DISCOUNT_EXPENSE) {
            return $unit > 0 ? -$unit : $unit;
        }

        $type = DB::table('fin_expenses')->where('id', $expense->ref_expense)->first();

        return match ((int) $type->ref_unity) {
            self::UNIT_BL => round($unit * $rate * (int) $booking->qtd_bl, 2),
            self::UNIT_PROCESS => round($unit * $rate / max(1, $this->namedBls($booking)), 2),
            self::UNIT_CONTAINER => round($unit * $rate * $this->containers($booking, $expense), 2),
            self::UNIT_WEIGHT_MEASURE => $this->weightMeasure($expense, $type, $rate, $unit),
            default => round($unit * $rate, 2),
        };
    }

    /** indice: quantas vezes o valor se repete (BLs, equipamentos) ou 1. */
    public function index(object $expense, Embarque $booking): float
    {
        $unity = (int) DB::table('fin_expenses')->where('id', $expense->ref_expense)->value('ref_unity');

        return match ($unity) {
            self::UNIT_BL => (float) $booking->qtd_bl,
            self::UNIT_CONTAINER => $this->containers($booking, $expense),
            default => 1.0,
        };
    }

    /** calculaValorUnitarioOrdem: o custo da despesa mais o das comissões ligadas a ela (mesmo equipamento, se houver). */
    public function unitWithCommissions(Embarque $booking, object $expense): float
    {
        $commissions = self::COMMISSIONS[(int) $expense->ref_expense] ?? [];

        if ($commissions === []) {
            return (float) $expense->buying_rate;
        }

        $extra = (float) DB::table('fin_expenses_values')
            ->where('ref_embarque', $booking->getKey())
            ->whereIn('ref_expense', $commissions)
            ->when((int) $expense->ref_expense !== 73 && (int) $expense->ref_expense !== 273 && (int) $expense->ref_expense !== 200 && $expense->ref_equipment, fn ($q) => $q->where('ref_equipment', $expense->ref_equipment))
            ->sum('buying_rate');

        return (float) $expense->buying_rate + $extra;
    }

    private function namedBls(Embarque $booking): int
    {
        return DB::table('bl')->where('embarque_id', $booking->getKey())->whereNotNull('bl')->where('bl', '<>', '')->where('bl', '<>', '0')->count();
    }

    private function containers(Embarque $booking, object $expense): float
    {
        return (float) DB::table('mercadoria')
            ->where('embarque_id', $booking->getKey())
            ->when(filled($expense->ref_equipment), fn ($q) => $q->where('tipo_container_id', $expense->ref_equipment))
            ->sum('quantidade');
    }

    private function weightMeasure(object $expense, object $type, float $rate, float $unit): float
    {
        $value = $expense->ref_container
            ? $unit * $rate * $this->factors->containerLclFactor(DB::table('container')->where('container_id', $expense->ref_container)->first())
            : $unit * $rate;

        if ((float) $type->min_value <= 0) {
            return $value;
        }

        $minimum = (float) $type->min_value * ((int) $type->ref_currency !== self::REAL ? $rate : 1);

        return max($value, $minimum);
    }
}
