<?php

namespace App\Actions\Cobranca\Items;

use App\Services\Cobranca\ChargeDetails;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Valor de um item da IC: Cobranca::calculaValorUnitario / calculaDebito (legado). Usado na geração e no recálculo. */
class ItemPricing
{
    /** Despesas de desconto: valor sempre negativo (inclui Desconto ISPS Code, 1482). */
    public const DISCOUNT_EXPENSES = [192, 271, 297, 892, 1056, 1482];

    /** Broker Commission / Arbitrary Fee etc.: somadas ao valor de outra despesa, nunca viram item próprio. */
    public const COMMISSION_EXPENSES = [33, 356, 387, 419, 1048];

    /** calculaValorUnitario: despesas cujo valor soma o de outras da reserva [despesa => [somadas, filtra equipamento]]. */
    private const COMMISSIONS = [
        1 => [[33, 1048], true],
        41 => [[419], true],
        200 => [[356], false],
        73 => [[387], false],
        273 => [[387], false],
    ];

    public function __construct(private ChargeDetails $factors) {}

    /** calculaValorUnitario: valor de venda mais o das despesas agregadas (Arbitrary/Collaboration Fee etc.). */
    public function unitValue(object $expense): float
    {
        [$added, $byEquipment] = self::COMMISSIONS[(int) $expense->ref_expense] ?? [[], false];

        $commission = $added === [] ? 0.0 : (float) DB::table('fin_expenses_values')
            ->where('ref_embarque', $expense->ref_embarque)
            ->whereIn('ref_expense', $added)
            ->when($byEquipment && $expense->ref_equipment, fn ($q) => $q->where('ref_equipment', $expense->ref_equipment))
            ->sum('selling_rate');

        return (float) $expense->selling_rate + $commission;
    }

    /** calculaDebito: valor do item conforme a unidade da despesa (1 BL, 2 CNT, 3 W/M, 5 Processo). */
    public function debit(object $expense, float $rate, float $unit, ?stdClass $bl): ?float
    {
        // despesa de outro BL não entra nesta IC
        if ($bl && $expense->ref_bl && (int) $expense->ref_bl !== (int) $bl->bl_id) {
            return null;
        }

        $embarqueId = $expense->ref_embarque;
        $container = fn () => DB::table('container')->where('container_id', $expense->ref_container)->first();

        return match ((int) $expense->despesa->ref_unity) {
            1 => round($unit * $rate * ($bl ? 1 : (float) DB::table('embarque')->where('embarque_id', $embarqueId)->value('qtd_bl')), 2),
            5 => round($unit * $rate / max(1, DB::table('bl')->where('embarque_id', $embarqueId)->whereNotNull('bl')->where('bl', '<>', '')->count()), 2),
            2 => round($unit * $rate * match (true) {
                ! $bl => (float) DB::table('mercadoria')->where('embarque_id', $embarqueId)
                    ->when(filled($expense->ref_equipment), fn ($q) => $q->where('tipo_container_id', $expense->ref_equipment))
                    ->sum('quantidade'),
                (bool) $expense->ref_container => $this->factors->containerFactor($container()),
                filled($expense->ref_equipment) => $this->factors->blFactorByType($bl, (int) $expense->ref_equipment),
                default => $this->factors->blFactor($bl),
            }, 2),
            3 => $this->weightMeasure($expense, $rate, $unit, $bl, $container),
            default => round($unit * $rate, 2),
        };
    }

    /** W/M: ton ou m³ do container/BL, respeitando o mínimo de venda (sr_min, convertido se não for BRL). */
    private function weightMeasure(object $expense, float $rate, float $unit, ?stdClass $bl, callable $container): float
    {
        if (! $expense->ref_container && ! $bl) {
            throw new DomainException('Despesa W/M sem container nem BL: não é possível calcular o fator.');
        }

        $value = $unit * $rate * ($expense->ref_container
            ? $this->factors->containerLclFactor($container())
            : $this->factors->blLclFactor($bl));

        if ((float) $expense->sr_min <= 0) {
            return $value;
        }

        $minimum = (float) $expense->sr_min * ((int) $expense->ref_currency_sr !== 1 ? $rate : 1);

        return $value > $minimum ? $value : $minimum;
    }
}
