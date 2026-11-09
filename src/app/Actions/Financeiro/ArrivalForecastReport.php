<?php

namespace App\Actions\Financeiro;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * financeiro/fluxo (legado, FluxoCaixaEmbarquesService), "Previsões de Chegadas": para cada reserva com chegada (IMPO) ou saída
 * (EXPO) prevista no período, as despesas de compra por BL (fator × compra unitária, mínimo, desconto negativo) convertidas em
 * reais pela cotação do cliente (ou a padrão) na data prevista. Reservas marítimas saem por BL; as demais, num grupo só.
 *
 * Preserva o legado: o fator de container usa só os containers já carregados (as reservas do período e os BLs do mesmo MBL),
 * não a base inteira, e sem cotação até a data usa a primeira que existir.
 */
class ArrivalForecastReport
{
    private const BRL = 1;

    private const UNIT_BL = 1;

    private const UNIT_CONTAINER = 2;

    private const UNIT_WEIGHT_MEASURE = 3;

    /** Despesas de desconto: o valor sai negativo. */
    private const DISCOUNTS = [192, 271, 297, 892, 1056, 1482];

    private const BILLING = ['a' => 'PA', 'c' => 'CC', 'p' => 'PP', 's' => 'SEG', 'f' => 'FI'];

    /** @var Collection<int, Collection<int, stdClass>> bl_id => containers */
    private Collection $containersByBl;

    /** @var array<int, stdClass> container_id => container */
    private array $containers = [];

    /** @var array{embarque: array, navio: array, mbl: array} chave => numero => container_id => container */
    private array $peers = ['embarque' => [], 'navio' => [], 'mbl' => []];

    /** @var array<int, array<int, array{taxa: int, perc: float}>> cliente_id => moeda => taxa; 0 = padrão */
    private array $rates = [];

    /** @var array<int, array<string, float>> cotação => data do lançamento => valor */
    private array $rateValues = [];

    /** @var list<string> */
    private array $dates = [];

    /**
     * @return array{sea: list<array<string, mixed>>, other: list<array<string, mixed>>}
     */
    public function __invoke(string $type, ?string $from, ?string $to): array
    {
        $result = ['sea' => [], 'other' => []];

        if (! $from && ! $to) {
            return $result;
        }

        $dateColumn = $type === 'e' ? 'saida_prevista' : 'chegada_prevista';
        $bookings = DB::table('embarque')
            ->where('impor_expor', $type)
            ->where('finalizado', '<>', 'c')
            ->when($from, fn ($q) => $q->where($dateColumn, '>=', $from))
            ->when($to, fn ($q) => $q->where($dateColumn, '<=', $to))
            ->orderBy($dateColumn)
            ->orderBy('embarque_id')
            ->get();

        if ($bookings->isEmpty()) {
            return $result;
        }

        $bls = DB::table('bl')->whereIn('embarque_id', $bookings->pluck('embarque_id'))->orderBy('bl_id')->get()->groupBy('embarque_id');
        $expenses = $this->expenses($bookings);
        $names = $this->names($bookings);
        $this->loadContainers($bookings, $bls->flatten());
        $this->loadRates($bookings, $dateColumn);

        foreach ($bookings as $booking) {
            $item = [
                'booking' => (string) $booking->booking,
                'embarque_id' => (int) $booking->embarque_id,
                'cliente' => $names['people'][$booking->cliente_id] ?? '',
                'transportadora' => $this->carrier($booking, $names),
                'etd' => $booking->saida_prevista,
                'eta' => $booking->chegada_prevista,
                'groups' => [],
            ];
            $bookingBls = $bls->get($booking->embarque_id, collect())->values();
            $bookingExpenses = $expenses['byBooking']->get($booking->embarque_id, collect());

            if ($booking->tipo_embarque === 'm') {
                foreach ($bookingBls as $i => $bl) {
                    $item['groups'][] = $this->group($booking, $bookingExpenses, $bl, $i === 0, $bl->bl ?: 'nameless BL', $expenses, $bookingBls);
                }
            } else {
                $item['groups'][] = $this->group($booking, $bookingExpenses, $bookingBls->first(), true, $booking->house_bl ?: (string) $booking->booking, $expenses, $bookingBls);
            }

            $result[$booking->tipo_embarque === 'm' ? 'sea' : 'other'][] = $item;
        }

        return $result;
    }

    /** @return array{byBooking: Collection, defs: Collection, currencies: Collection, equipment: Collection} */
    private function expenses(Collection $bookings): array
    {
        $values = DB::table('fin_expenses_values')->whereIn('ref_embarque', $bookings->pluck('embarque_id'))->orderBy('id')->get();

        return [
            'byBooking' => $values->groupBy('ref_embarque'),
            'defs' => DB::table('fin_expenses as e')->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
                ->whereIn('e.id', $values->pluck('ref_expense')->filter()->unique())
                ->get(['e.id', 'e.name', 'e.ref_unity', 'u.acronym'])->keyBy('id'),
            'currencies' => DB::table('cmn_currencies')->whereIn('id', $values->pluck('ref_currency_br')->filter()->unique())->pluck('symbol', 'id'),
            'equipment' => DB::table('tipo_container')->whereIn('tipo_container_id', $values->pluck('ref_equipment')->filter()->unique())->pluck('nome', 'tipo_container_id'),
        ];
    }

    /** @return array{people: Collection, carriers: Collection, airlines: Collection} */
    private function names(Collection $bookings): array
    {
        return [
            'people' => DB::table('pessoa')->whereIn('pessoa_id', $bookings->pluck('cliente_id')->filter()->unique())->pluck('nome', 'pessoa_id'),
            'carriers' => DB::table('armador')->whereIn('armador_id', $bookings->pluck('armador_id')->filter()->unique())->pluck('nome', 'armador_id'),
            'airlines' => DB::table('cia_aerea')->whereIn('id', $bookings->pluck('ref_cia_aerea')->filter()->unique())->pluck('name', 'id'),
        ];
    }

    private function carrier(stdClass $booking, array $names): string
    {
        if ($booking->tipo_embarque === 'a' && ($airline = $names['airlines'][$booking->ref_cia_aerea] ?? null)) {
            return $airline;
        }

        return $names['carriers'][$booking->armador_id] ?? '';
    }

    /** Containers dos BLs das reservas, mais os de mesmo número em outros BLs do mesmo MBL (para repartir o volume). */
    private function loadContainers(Collection $bookings, Collection $bls): void
    {
        $bookingById = $bookings->keyBy('embarque_id');
        $blById = $bls->keyBy('bl_id');
        $own = DB::table('container')->whereIn('bl_id', $bls->pluck('bl_id'))->orderBy('container_id')->get();
        $this->containersByBl = $own->groupBy('bl_id');

        foreach ($own as $container) {
            $this->index($container, $blById[$container->bl_id], $bookingById);
        }

        $numbers = $own->pluck('numero')->filter()->unique();
        $mbls = $bls->pluck('mbl_number')->filter()->unique();

        if ($numbers->isEmpty() || $mbls->isEmpty()) {
            return;
        }

        $extra = DB::table('container as c')->join('bl', 'bl.bl_id', '=', 'c.bl_id')
            ->whereIn('c.numero', $numbers)->whereIn('bl.mbl_number', $mbls)
            ->get(['c.*', 'bl.embarque_id', 'bl.mbl_number']);

        foreach ($extra as $container) {
            $this->index($container, $container, $bookingById);
        }
    }

    private function index(stdClass $container, stdClass $bl, Collection $bookingById): void
    {
        $this->containers[$container->container_id] = $container;

        if (! $container->numero) {
            return;
        }

        $this->peers['embarque'][$bl->embarque_id][$container->numero][$container->container_id] = $container;

        if ($navio = $bookingById->get($bl->embarque_id)?->navio_id) {
            $this->peers['navio'][$navio][$container->numero][$container->container_id] = $container;
        }

        if ($bl->mbl_number) {
            $this->peers['mbl'][$bl->mbl_number][$container->numero][$container->container_id] = $container;
        }
    }

    private function loadRates(Collection $bookings, string $dateColumn): void
    {
        $this->dates = $bookings->pluck($dateColumn)->filter()->unique()->sort()->values()->all();

        foreach ([0 => DB::table('fin_default_rates as r')->selectRaw('0 as pessoa, r.ref_currency_rate, r.default_tax as tax'),
            1 => DB::table('fin_companies_rates as r')->selectRaw('r.ref_pessoa as pessoa, r.ref_currency_rate, r.tax')
                ->whereIn('r.ref_pessoa', $bookings->pluck('cliente_id')->filter()->unique())] as $query) {
            foreach ($query->join('fin_currencies_rates as c', 'c.id', '=', 'r.ref_currency_rate')->addSelect('c.ref_currency')->orderBy('r.id')->get() as $row) {
                $this->rates[$row->pessoa][$row->ref_currency] = ['taxa' => (int) $row->ref_currency_rate, 'perc' => (float) $row->tax];
            }
        }

        $taxas = collect($this->rates)->flatten(1)->pluck('taxa')->unique();

        //  lê o histórico de cada cotação até a última data prevista; se pesar, buscar por data como o legado
        $values = DB::table('fin_currencies_rates_values')->whereIn('ref_currency_rate', $taxas)->where('rate_date', '<=', end($this->dates) ?: '9999-12-31')
            ->orderBy('rate_date')->orderBy('id')->get(['ref_currency_rate', 'rate_date', 'rate']);

        foreach ($values as $value) {
            $this->rateValues[$value->ref_currency_rate][substr($value->rate_date, 0, 10)] = (float) $value->rate;
        }
    }

    private function group(stdClass $booking, Collection $expenses, ?stdClass $bl, bool $firstBl, string $title, array $lookup, Collection $bls): array
    {
        $lines = [];
        $total = 0.0;

        foreach ($expenses as $expense) {
            if ($line = $this->line($booking, $expense, $bl, $firstBl, $lookup, $bls)) {
                $total += $line['converted'];
                $lines[] = $line;
            }
        }

        return ['title' => $title, 'expenses' => $lines, 'total' => $total];
    }

    private function line(stdClass $booking, stdClass $expense, ?stdClass $bl, bool $firstBl, array $lookup, Collection $bls): ?array
    {
        if ($expense->ref_bl && (! $bl || (int) $expense->ref_bl !== (int) $bl->bl_id)) {
            return null;
        }

        $def = $lookup['defs'][$expense->ref_expense] ?? null;
        $unit = (int) ($def->ref_unity ?? 0);

        if ($unit === self::UNIT_BL && ! $expense->ref_bl && ! $firstBl) {
            return null;
        }

        $factor = (float) $this->factor($booking, $expense, $bl, $unit);

        if ($factor == 0.0 && $unit === self::UNIT_CONTAINER) {
            return null;
        }

        $amount = (float) $expense->buying_rate * $factor;

        if ((float) $expense->br_min > 0 && $amount <= (float) $expense->br_min) {
            $amount = (float) $expense->br_min;
        }

        if (in_array((int) $expense->ref_expense, self::DISCOUNTS, true) && $amount > 0) {
            $amount = -$amount;
        }

        $rate = $this->rate($booking, $expense);

        return [
            'name' => $this->name($expense, $def, $lookup, $bls),
            'billing' => self::BILLING[$expense->billing] ?? '',
            'factor' => $factor,
            'currency' => $lookup['currencies'][$expense->ref_currency_br] ?? '',
            'unit_cost' => (float) $expense->buying_rate,
            'total_cost' => $amount,
            'rate' => $rate,
            'converted' => $amount * $rate,
        ];
    }

    /** FinExpensesValues::getFator (legado) pelo lado do fluxo. */
    private function factor(stdClass $booking, stdClass $expense, ?stdClass $bl, int $unit): ?float
    {
        if ($unit === self::UNIT_WEIGHT_MEASURE && $booking->impor_expor === 'i') {
            $byBl = $bl ? $this->lclFactor($bl) : null;

            return $byBl && $byBl != 1 ? $byBl : $this->importFactor($booking);
        }

        return match ($unit) {
            self::UNIT_CONTAINER => $this->containerFactor($booking, $expense, $bl),
            self::UNIT_WEIGHT_MEASURE => $bl ? $this->lclFactor($bl) : $this->importFactor($booking),
            default => 1.0,
        };
    }

    private function containerFactor(stdClass $booking, stdClass $expense, ?stdClass $bl): float
    {
        if (! $bl) {
            return 0.0;
        }

        if ($expense->ref_equipment) {
            return $this->containersByBl->get($bl->bl_id, collect())
                ->where('tipo_container_id', $expense->ref_equipment)
                ->sum(fn ($c) => $this->share($booking, $bl, $c, true));
        }

        if ($expense->ref_container) {
            $container = $this->containers[$expense->ref_container] ?? null;

            return $container && (int) $container->bl_id === (int) $bl->bl_id ? $this->share($booking, $bl, $container, true) : 0.0;
        }

        return $this->containersByBl->get($bl->bl_id, collect())->sum(fn ($c) => $this->share($booking, $bl, $c, false));
    }

    /** Fração do volume do container sobre o total do mesmo container físico (Container::getFatorCtn / Bl::getFatorCtn). */
    private function share(stdClass $booking, stdClass $bl, stdClass $container, bool $byMbl): float
    {
        $peers = [$container];

        if ($container->numero) {
            $peers = $booking->impor_expor === 'e'
                ? $this->peers['embarque'][$booking->embarque_id][$container->numero] ?? $peers
                : (($byMbl && $bl->mbl_number ? $this->peers['mbl'][$bl->mbl_number][$container->numero] ?? null : null)
                    ?? ($booking->navio_id ? $this->peers['navio'][$booking->navio_id][$container->numero] ?? null : null)
                    ?? $peers);
        }

        $total = collect($peers)->sum(fn ($c) => $this->number($c->volume));

        return $total > 0 ? $this->number($container->volume) / $total : 0.0;
    }

    private function lclFactor(stdClass $bl): float
    {
        $weight = $this->number($bl->gross_weight);
        $volume = $this->number($bl->volume);

        return $weight / 1000 > $volume ? $weight / 1000 : $volume;
    }

    /** Embarque::getFatorLclImpo (legado). */
    private function importFactor(stdClass $booking): ?float
    {
        $weight = (float) $booking->peso_cotacao;
        $volume = (float) $booking->volume_cotacao;

        if ($booking->impor_expor !== 'i' || (! $weight && ! $volume)) {
            return null;
        }

        return max($booking->tipo_embarque === 'm' ? $volume : $volume / 0.006, $weight);
    }

    /** Cotação de compra: 1 em reais; senão a do cliente (ou a padrão) na data prevista, com o percentual. */
    private function rate(stdClass $booking, stdClass $expense): float
    {
        $currency = (int) $expense->ref_currency_br;

        if (! $currency || $currency === self::BRL) {
            return 1.0;
        }

        $config = $this->rates[(int) $booking->cliente_id][$currency] ?? $this->rates[0][$currency] ?? null;
        $date = $booking->impor_expor === 'e' ? $booking->saida_prevista : $booking->chegada_prevista;
        $rate = $config ? $this->rateOn($config['taxa'], substr((string) $date, 0, 10)) : null;

        return $rate === null ? 0.0 : $rate + $rate * $config['perc'] / 100;
    }

    private function rateOn(int $taxa, string $date): ?float
    {
        $values = $this->rateValues[$taxa] ?? [];

        foreach ([$date, ...$this->dates] as $day) {
            $found = collect($values)->filter(fn ($v, $d) => $d <= $day)->last();

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function name(stdClass $expense, ?stdClass $def, array $lookup, Collection $bls): string
    {
        $name = ($def->name ?? '').'/'.($def->acronym ?? '');

        if ($expense->ref_equipment && ($equipment = $lookup['equipment'][$expense->ref_equipment] ?? null)) {
            $name .= " ({$equipment})";
        }

        if ($expense->ref_bl && ($bl = $bls->firstWhere('bl_id', $expense->ref_bl))) {
            $name .= " ({$bl->bl})";
        }

        if ($expense->ref_container && ($container = $this->containers[$expense->ref_container] ?? null)) {
            $name .= " {$container->numero}";
        }

        return $name;
    }

    /** Reserva.toFloat (legado): número com vírgula decimal e ponto de milhar. */
    private function number(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return is_numeric($value) ? (float) $value : (float) str_replace(',', '.', str_replace('.', '', $value));
    }
}
