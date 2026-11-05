<?php

namespace App\Actions\VariedExpenses;

use App\Models\Embarque;
use App\Services\Cobranca\ChargeDetails;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * loadBooking (legado): as reservas com despesas lançadas (fin_expenses_values) e, de cada uma, as linhas de compra e venda
 * por BL que o formulário mostra ao lado da nota.
 *
 * Preserva o legado: cada despesa vale para todos os BLs da reserva (não olha `ref_bl`) e só entra a linha com fator maior
 * que zero.
 */
class BookingExpenseLines
{
    private const BILLING = ['a' => 'PA', 'c' => 'CC', 'p' => 'PP', 's' => 'SEG', 'f' => 'FI'];

    private const UNIT_CONTAINER = 2;

    private const UNIT_WEIGHT_MEASURE = 3;

    public function __construct(private ChargeDetails $factors) {}

    /**
     * O legado compara o booking inteiro; aqui a busca é por trecho, para o campo de seleção.
     *
     * @return Collection<int, Embarque>
     */
    public function bookings(string $search): Collection
    {
        return Embarque::query()
            ->whereIn('embarque_id', DB::table('fin_expenses_values')->select('ref_embarque'))
            ->where('booking', 'like', '%'.trim($search).'%')
            ->orderByDesc('embarque_id')
            ->limit(30)
            ->get();
    }

    /** @return list<array{bl:?string, expense:?string, purchase:string, sale:string, billing:string, due_to:string}> */
    public function __invoke(Embarque $booking): array
    {
        $bls = DB::table('bl')->where('embarque_id', $booking->getKey())->orderBy('bl_id')->get();
        $lines = [];

        foreach ($this->expenses($booking) as $expense) {
            foreach ($bls as $bl) {
                $factor = $this->factor($expense, $bl);

                if ($factor > 0) {
                    $lines[] = [
                        'bl' => $bl->bl,
                        'expense' => $expense->expense_name.($expense->unity_acronym ? " / {$expense->unity_acronym}" : ''),
                        'purchase' => $this->money($expense->br_symbol, $expense->buying_rate * $factor),
                        'sale' => $this->money($expense->sr_symbol, $expense->selling_rate * $factor),
                        'billing' => self::BILLING[$expense->billing] ?? '',
                        'due_to' => (string) $expense->due_to_name,
                    ];
                }
            }
        }

        return $lines;
    }

    /** @return Collection<int, stdClass> */
    private function expenses(Embarque $booking): Collection
    {
        return DB::table('fin_expenses_values as v')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->leftJoin('fin_expenses_values_due_to as d', 'd.id', '=', 'v.ref_due_to')
            ->leftJoin('cmn_currencies as cb', 'cb.id', '=', 'v.ref_currency_br')
            ->leftJoin('cmn_currencies as cs', 'cs.id', '=', 'v.ref_currency_sr')
            ->where('v.ref_embarque', $booking->getKey())
            ->orderBy('v.id')
            ->get(['v.*', 'e.name as expense_name', 'e.ref_unity', 'u.acronym as unity_acronym', 'd.name as due_to_name', 'cb.symbol as br_symbol', 'cs.symbol as sr_symbol']);
    }

    /** FinExpensesValues::getFator (legado). */
    public function factor(stdClass $expense, stdClass $bl): float
    {
        return match ((int) $expense->ref_unity) {
            self::UNIT_CONTAINER => match (true) {
                (bool) $expense->ref_equipment => $this->factors->blFactorByType($bl, (int) $expense->ref_equipment),
                (bool) $expense->ref_container => $this->containerFactor($expense, $bl),
                default => $this->factors->blFactor($bl),
            },
            self::UNIT_WEIGHT_MEASURE => $this->factors->blLclFactor($bl),
            default => 1.0,
        };
    }

    private function containerFactor(stdClass $expense, stdClass $bl): float
    {
        $container = DB::table('container')->where('container_id', $expense->ref_container)->first();

        return $container && (int) $container->bl_id === (int) $bl->bl_id ? $this->factors->containerFactor($container) : 0.0;
    }

    private function money(?string $symbol, float|string|null $value): string
    {
        return trim("{$symbol} ".number_format((float) $value, 2, ',', '.'));
    }
}
