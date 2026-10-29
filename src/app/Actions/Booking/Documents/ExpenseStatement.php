<?php

namespace App\Actions\Booking\Documents;

use App\Actions\Booking\ExpenseTracking;
use App\Models\Embarque;
use App\Services\Cobranca\ChargeDetails;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Relação de despesas da reserva em três versões (legado): valoresCompra (custo), valoresDespesa (venda convertida em reais)
 * e valoresDespesasDocumentacao (venda na moeda original). Devolve as tabelas já formatadas para o PDF.
 *
 * Preserva dois defeitos do legado (ver despesas-embarque.md): na venda marítima com mínimo o valor exibido omite o fator
 * mas o total o inclui; e na venda aérea o fator de uma linha vale o da anterior quando a unidade não é W/M nem Processo.
 */
class ExpenseStatement
{
    public const PURCHASE = 'purchase';

    public const SALE = 'sale';

    public const DOCUMENTATION = 'documentation';

    /** Due To "Correio": nunca aparece na relação. */
    private const MAIL_DUE_TO = 10;

    private const UNIT_CONTAINER = 2;

    private const UNIT_WEIGHT_MEASURE = 3;

    private const UNIT_PROCESS = 5;

    public function __construct(private ChargeDetails $factors) {}

    /**
     * @return array{columns: list<array{0: string, 1: int, 2: string}>, groups: list<array{title: ?string, rows: list<list<string>>}>, total: ?string, footnote: ?string, message: ?string}
     *
     * @throws DomainException sem cotação da moeda, ou documentação de reserva que não é marítima
     */
    public function __invoke(Embarque $booking, string $kind): array
    {
        if ($kind === self::DOCUMENTATION && $booking->tipo_embarque !== 'm') {
            throw new DomainException('A relação para documentação só existe para reservas marítimas.');
        }

        $statement = match ($kind) {
            self::PURCHASE => ['columns' => [['Despesa', 80, 'L'], ['Tipo', 10, 'L'], ['Fator', 20, 'R'], ['Valor Compra Unitário', 35, 'R'], ['Valor Compra Total', 35, 'R']]],
            self::SALE => ['columns' => [['Despesa', 70, 'L'], ['Tipo', 10, 'L'], ['Valor Unitário', 25, 'R'], ['Fator', 20, 'R'], ['Cotação', 20, 'R'], ['Valor Total *', 35, 'R']]],
            self::DOCUMENTATION => ['columns' => [['Despesa', 80, 'L'], ['Tipo', 10, 'L'], ['Fator', 20, 'R'], ['Valor Unitário', 35, 'R'], ['Valor Total', 35, 'R']]],
        };

        return [...$statement, 'groups' => [], 'total' => null, 'footnote' => null, 'message' => null, ...match ($kind) {
            self::PURCHASE => $booking->tipo_embarque === 'm' ? $this->purchaseByBl($booking) : $this->purchaseWithoutBl($booking),
            self::SALE => $this->sale($booking),
            self::DOCUMENTATION => $this->documentation($booking),
        }];
    }

    /** @return array{groups: list<array{title: ?string, rows: list<list<string>>}>} */
    private function purchaseByBl(Embarque $booking): array
    {
        return ['groups' => $this->byBl($booking, fn ($d) => $d->ref_due_to !== self::MAIL_DUE_TO, function (stdClass $d, float $factor) {
            $total = $d->buying_rate * $factor;

            return [
                number_format($factor, 4, ',', '.'),
                $this->money($d->br_symbol, $d->buying_rate),
                $this->money($d->br_symbol, (float) $d->br_min > 0 && $total <= $d->br_min ? $d->br_min : $total),
            ];
        })];
    }

    /** Aéreo: fator da cotação; só W/M multiplica pelo fator. */
    private function purchaseWithoutBl(Embarque $booking): array
    {
        $factor = round((float) $this->importFactor($booking), 2);

        if ($factor <= 0) {
            return ['message' => 'É necessário que o fator seja maior que 0'];
        }

        $rows = $this->expenses($booking)->map(fn (stdClass $d) => [
            $this->label($d),
            ExpenseTracking::BILLING[$d->billing] ?? '',
            number_format($factor, 3, ',', '.'),
            $this->money($d->br_symbol, $d->buying_rate),
            $this->money($d->br_symbol, $d->buying_rate * ((int) $d->ref_unity === self::UNIT_WEIGHT_MEASURE ? $factor : 1), 3),
        ])->all();

        return ['groups' => [['title' => null, 'rows' => $rows]]];
    }

    private function sale(Embarque $booking): array
    {
        $maritime = $booking->tipo_embarque === 'm';
        $total = 0.0;
        $rate = fn (stdClass $d) => $this->quote($d->ref_currency_sr, $d->sr_symbol);

        if ($maritime) {
            $billing = $booking->impor_expor === 'i' ? 'c' : 'p';
            $groups = $this->byBl($booking, fn ($d) => $d->billing === $billing && $d->selling_rate > 0 && $d->ref_due_to !== self::MAIL_DUE_TO && (string) $d->all_in !== '1',
                function (stdClass $d, float $factor) use (&$total, $rate) {
                    $quote = $rate($d);
                    $gross = $d->selling_rate * $factor;
                    $minimum = (float) $d->sr_min > 0;
                    $total += ($minimum && $gross <= $d->sr_min ? $d->sr_min * $factor : $gross) * $quote;
                    $shown = $minimum && $gross <= $d->sr_min ? $d->sr_min * $quote : $gross * $quote;

                    return [
                        $this->money($d->sr_symbol, $d->selling_rate),
                        number_format($factor, 4, ',', '.'),
                        number_format($quote, 4, ',', '.'),
                        $this->money('R$', $shown),
                    ];
                });
        } else {
            $factor = 0.0;
            $rows = [];

            foreach ($this->expenses($booking)->filter(fn ($d) => ! $d->ref_bl && ! $d->ref_container && $d->ref_due_to !== self::MAIL_DUE_TO && (string) $d->all_in !== '1') as $d) {
                if ((int) $d->ref_unity === self::UNIT_WEIGHT_MEASURE && $booking->impor_expor === 'i') {
                    $factor = (float) $this->importFactor($booking);
                } elseif ((int) $d->ref_unity === self::UNIT_PROCESS) {
                    $factor = 1.0;
                }

                $quote = $rate($d);
                $total += $d->selling_rate * $factor * $quote;
                $rows[] = [
                    $this->label($d),
                    ExpenseTracking::BILLING[$d->billing] ?? '',
                    $this->money($d->sr_symbol, $d->selling_rate),
                    number_format($factor, 4, ',', '.'),
                    number_format($quote, 4, ',', '.'),
                    $this->money('R$', $d->selling_rate * $factor * $quote),
                ];
            }

            $groups = [['title' => null, 'rows' => $rows]];
        }

        $date = now()->format('d/m/Y');
        $stage = $booking->impor_expor === 'i' ? 'chegada de sua carga no porto/aeroporto de destino' : 'embarque de sua carga no porto/aeroporto de origem';

        return [
            'groups' => $groups,
            'total' => 'R$ '.number_format($total, 2, ',', '.'),
            'footnote' => ($booking->impor_expor === 'i' ? '*' : '')."Este documento é uma previsão, utilizando-se como base de conversão a cotação das moedas estrangeiras com data de {$date}.\nOs valores finais serão enviados, atualizados, no momento da confirmação de {$stage}.",
        ];
    }

    private function documentation(Embarque $booking): array
    {
        return ['groups' => $this->byBl($booking, fn ($d) => $d->ref_due_to !== self::MAIL_DUE_TO && (string) $d->all_in !== '1', function (stdClass $d, float $factor) {
            $gross = $d->selling_rate * $factor;

            return [
                number_format($factor, 4, ',', '.'),
                $this->money($d->sr_symbol, $d->selling_rate),
                $this->money($d->sr_symbol, (float) $d->sr_min > 0 && $gross <= $d->sr_min ? $d->sr_min : $gross),
            ];
        })];
    }

    /**
     * Uma tabela por BL com as despesas que lhe pertencem (sem BL nem container, do BL, ou de container do BL).
     *
     * @param  callable(stdClass): bool  $filter
     * @param  callable(stdClass, float): list<string>  $values  células depois de despesa e tipo
     * @return list<array{title: ?string, rows: list<list<string>>}>
     */
    private function byBl(Embarque $booking, callable $filter, callable $values): array
    {
        $expenses = $this->expenses($booking)->filter($filter);
        $groups = [];

        foreach ($booking->bls()->orderBy('bl_id')->get() as $bl) {
            $bl = (object) $bl->getAttributes();
            $rows = [];

            foreach ($expenses->filter(fn ($d) => $this->belongsTo($d, $bl)) as $d) {
                $rows[] = [$this->label($d), ExpenseTracking::BILLING[$d->billing] ?? '', ...$values($d, $this->factor($d, $bl, $booking))];
            }

            $groups[] = ['title' => (string) $bl->bl, 'rows' => $rows];
        }

        return $groups;
    }

    /** @return Collection<int, stdClass> */
    private function expenses(Embarque $booking)
    {
        return DB::table('fin_expenses_values as v')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->leftJoin('cmn_currencies as cb', 'cb.id', '=', 'v.ref_currency_br')
            ->leftJoin('cmn_currencies as cs', 'cs.id', '=', 'v.ref_currency_sr')
            ->where('v.ref_embarque', $booking->getKey())
            ->orderBy('v.id')
            ->get(['v.*', 'v.ref_bl as v_bl', 'e.name as expense_name', 'e.ref_unity', 'u.acronym as unity_name', 'cb.symbol as br_symbol', 'cs.symbol as sr_symbol'])
            ->map(function (stdClass $d) {
                $d->ref_due_to = $d->ref_due_to === null ? null : (int) $d->ref_due_to;

                return $d;
            });
    }

    private function label(stdClass $d): string
    {
        return $this->factors->expenseLabel($d);
    }

    private function belongsTo(stdClass $d, stdClass $bl): bool
    {
        if (! $d->ref_bl && ! $d->ref_container) {
            return true;
        }

        return (int) $d->ref_bl === (int) $bl->bl_id
            || ($d->ref_container && (int) DB::table('container')->where('container_id', $d->ref_container)->value('bl_id') === (int) $bl->bl_id);
    }

    /** FinExpensesValues::getFator, e o fator da cotação nas despesas W/M da importação. */
    private function factor(stdClass $d, stdClass $bl, Embarque $booking): float
    {
        $own = match ((int) $d->ref_unity) {
            self::UNIT_CONTAINER => match (true) {
                (bool) $d->ref_equipment => $this->factors->blFactorByType($bl, (int) $d->ref_equipment),
                (bool) $d->ref_container => $this->containerFactor($d, $bl),
                default => $this->factors->blFactor($bl),
            },
            self::UNIT_WEIGHT_MEASURE => $this->factors->blLclFactor($bl),
            default => 1.0,
        };

        if ((int) $d->ref_unity === self::UNIT_WEIGHT_MEASURE && $booking->impor_expor === 'i') {
            return $own && $own !== 1.0 ? $own : (float) $this->importFactor($booking);
        }

        return $own;
    }

    private function containerFactor(stdClass $d, stdClass $bl): float
    {
        $container = DB::table('container')->where('container_id', $d->ref_container)->first();

        return $container && (int) $container->bl_id === (int) $bl->bl_id ? $this->factors->containerFactor($container) : 0.0;
    }

    /** Embarque::getFatorLclImpo: o maior entre peso e volume da cotação (aéreo: volume ÷ 0,006). */
    private function importFactor(Embarque $booking): ?float
    {
        $weight = (float) $booking->peso_cotacao;
        $volume = (float) $booking->volume_cotacao;

        if ($booking->impor_expor !== 'i' || (! $weight && ! $volume)) {
            return null;
        }

        return max($booking->tipo_embarque === 'm' ? $volume : $volume / 0.006, $weight);
    }

    /**
     * FinExpensesValues::getValorCotacao: último valor da taxa padrão da moeda de venda (1 para real ou sem moeda).
     *
     * @throws DomainException
     */
    private function quote(?int $currency, ?string $symbol): float
    {
        if (! $currency || $currency === 1) {
            return 1.0;
        }

        $rate = DB::table('fin_default_rates as d')
            ->join('fin_currencies_rates as r', 'r.id', '=', 'd.ref_currency_rate')
            ->where('r.ref_currency', $currency)
            ->value('d.ref_currency_rate');

        if (! $rate) {
            throw new DomainException("Não há cotação para a moeda {$symbol}");
        }

        $value = DB::table('fin_currencies_rates_values')->where('ref_currency_rate', $rate)->orderByDesc('rate_date')->value('rate');

        return match (true) {
            $value === null => throw new DomainException("A cotação para a moeda {$symbol} está desatualizada"),
            (float) $value <= 0 => throw new DomainException("A cotação para a moeda {$symbol} está com valores inválidos"),
            default => (float) $value,
        };
    }

    private function money(?string $symbol, float|string|null $value, int $decimals = 2): string
    {
        return trim("{$symbol} ".number_format((float) $value, $decimals, ',', '.'));
    }
}
