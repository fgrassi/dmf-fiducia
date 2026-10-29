<?php

namespace App\Actions\PaymentOrders;

use App\Services\Cobranca\ChargeDetails;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * calculaDebito e calculaCotacao (legado, ordemPagamento): valor em reais de um item da ordem e a taxa de câmbio que ele usa.
 * Preserva o legado em dois pontos: a despesa desligada em qualquer ordem do mesmo MBL zera o item, e o fator W/M do
 * pagamento soma o peso e o volume de todos os BLs do MBL (mesmo de outras reservas).
 */
class OrderItemValue
{
    private const UNIT_BL = 1;

    private const UNIT_CONTAINER = 2;

    private const UNIT_WEIGHT_MEASURE = 3;

    private const UNIT_PROCESS = 5;

    private const REAL = 1;

    public function __construct(private ChargeDetails $factors) {}

    /**
     * @param  object  $expense  linha de fin_expenses_values
     * @param  ?string  $mbl  MBL da ordem
     */
    public function debit(object $expense, float $rate, float $unit, ?string $mbl): float
    {
        if ($mbl !== null && DB::table('fin_payment_order_items_disabled as d')
            ->join('fin_payment_orders as o', 'o.id', '=', 'd.ref_fin_payment_order')
            ->where('d.ref_expense', $expense->id)->where('d.status', 0)->where('o.mbl', $mbl)->exists()) {
            return 0.0;
        }

        $unity = (int) DB::table('fin_expenses')->where('id', $expense->ref_expense)->value('ref_unity');

        if (in_array($unity, [self::UNIT_BL, self::UNIT_PROCESS], true)) {
            return round($unit * $rate, 2);
        }

        $bls = DB::table('bl')->where('embarque_id', $expense->ref_embarque)->orderBy('bl_id')->get();
        $total = 0.0;

        foreach ($bls as $bl) {
            if (trim((string) $mbl) !== '' && trim((string) $bl->mbl_number) !== trim((string) $mbl)) {
                continue;
            }

            if ($expense->ref_bl && (int) $expense->ref_bl !== (int) $bl->bl_id) {
                continue;
            }

            $total += match ($unity) {
                self::UNIT_CONTAINER => round($unit * $rate * $this->containerQuantity($expense, $bl), 2),
                self::UNIT_WEIGHT_MEASURE => $this->weightMeasure($expense, $bl, $unit, $rate, $bls->count()),
                default => round($unit * $rate, 2),
            };
        }

        return $total;
    }

    /**
     * Taxa do câmbio do item: 1 para real; senão a negociada da despesa, a negociada da moeda (a mais recente, se sem
     * despesa) ou a cotação de hoje do armador para pagamento.
     *
     * @throws DomainException sem cotação de hoje
     */
    public function quote(int $currency, ?int $carrier, string $operation, int $orderId, int $expenseValueId): float
    {
        if ($currency === self::REAL) {
            return 1.0;
        }

        $custom = DB::table('fin_custom_payment_order_currency_rate')
            ->where('ref_currency', $currency)->where('ref_payment_order', $orderId)->orderByDesc('id')->get();

        if ($custom->isNotEmpty()) {
            $forExpense = $custom->firstWhere('ref_expense', $expenseValueId);

            if ($forExpense && (float) $forExpense->custom_rate) {
                return (float) $forExpense->custom_rate;
            }

            if (! $custom->first()->ref_expense) {
                return (float) $custom->first()->custom_rate;
            }
        }

        $value = DB::table('fin_currencies_rates_armador_payment_values as v')
            ->join('fin_currencies_rates_armador_payment as p', 'p.id', '=', 'v.ref_currency_rate_armador')
            ->where('v.rate_date', now()->toDateString())
            ->where('p.ref_currency', $currency)->where('p.ref_armador', $carrier)->where('p.type', $operation)
            ->orderByDesc('v.id')
            ->value('v.rate');

        return $value !== null ? round((float) $value, 4) : throw new DomainException('Não há cotação do armador para hoje.');
    }

    private function containerQuantity(object $expense, stdClass $bl): float
    {
        return match (true) {
            (bool) $expense->ref_container => $this->factors->containerFactor(DB::table('container')->where('container_id', $expense->ref_container)->first()),
            filled($expense->ref_equipment) => $this->factors->blFactorByType($bl, (int) $expense->ref_equipment),
            default => $this->factors->blFactor($bl),
        };
    }

    /** W/M: ton ou m³ (do container, ou do MBL dividido pelos BLs da reserva), respeitando o custo mínimo. */
    private function weightMeasure(object $expense, stdClass $bl, float $unit, float $rate, int $blCount): float
    {
        $value = $expense->ref_container
            ? $unit * $this->factors->containerLclFactor(DB::table('container')->where('container_id', $expense->ref_container)->first()) * $rate
            : $unit * $this->mblLclFactor($bl) * $rate / $blCount;

        if ((float) $expense->br_min <= 0) {
            return $value;
        }

        $minimum = ((float) $expense->br_min * ((int) $expense->ref_currency_br !== self::REAL ? $rate : 1)) / $blCount;

        return $value > $minimum ? $value : $minimum;
    }

    /** Bl::getFatorLclPgto: maior entre peso (ton) e volume somados em todos os BLs do mesmo MBL. */
    private function mblLclFactor(stdClass $bl): float
    {
        if ($bl->mbl_number === null) {
            return 0.0;
        }

        $parse = fn (?string $value) => (float) str_replace(',', '.', str_replace('.', '', (string) $value));
        $rows = DB::table('bl')->where('mbl_number', $bl->mbl_number)->get(['gross_weight', 'volume']);
        $weight = $rows->sum(fn ($row) => $parse($row->gross_weight));
        $volume = $rows->sum(fn ($row) => $parse($row->volume));

        return max($weight / 1000, $volume);
    }
}
