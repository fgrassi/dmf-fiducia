<?php

namespace App\Actions\Fiducia;

use App\Actions\VariedExpenses\BookingExpenseLines;
use App\Models\CapaProcesso;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * relatorioFiduciaDesconto (legado), "Relatório de Embarques - Clientes Fiducia": por cliente com desconto Fiducia
 * (`fiducia_desconto`), embarques e equipamentos confirmados (data efetiva no período) e não confirmados (sem data efetiva,
 * data prevista no período), médias mensais e lucratividade.
 *
 * Lucratividade: confirmado = resultado das capas da reserva + receitas/despesas ainda fora de capa (estimadas pela Taxa
 * Diária, "provisória"); não confirmado = saldo do resumo de custos e receitas da reserva (`fin_expenses_values`) na última
 * taxa de cada moeda. Reserva confirmada sem capa e sem estimativa fica sem lucratividade.
 *
 * @phpstan-type Filters array{mes_ini:int|string|null, ano_ini:int|string|null, mes_fim:int|string|null, ano_fim:int|string|null, cliente_id?:int|string|null, campo_data?:?string, impor_expor?:?string, agrupar_por_mes?:bool, listar_embarques?:bool, apenas_com_capa?:bool}
 */
class ClientBookingsReport
{
    public const MONTHS = [1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARCO', 4 => 'ABRIL', 5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO', 9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO'];

    public const DATE_FIELDS = ['saida_efetiva' => 'Data efetiva de embarque', 'chegada_efetiva' => 'Data efetiva de chegada'];

    public const TYPES = ['i' => 'IMPO', 'e' => 'EXPO'];

    private const UNIT_CONTAINER = 2;

    private const UNIT_WEIGHT_MEASURE = 3;

    /** @var array<int, float> última Taxa Diária por moeda (a de real é 1) */
    private array $dailyRates = [1 => 1.0];

    private ?float $dollarRate = null;

    private ?string $dollarRateDate = null;

    /** @var array<int, array{receitas: float, despesas: float, provisoria: bool}> */
    private array $pending = [];

    public function __construct(private BookingExpenseLines $expenseLines) {}

    /**
     * @param  Filters  $input
     * @return array<string, mixed>
     *
     * @throws DomainException filtro inválido (mesmas mensagens do legado)
     */
    public function __invoke(array $input): array
    {
        $filters = $this->parse($input);
        $this->loadDailyRates();

        $clients = DB::table('fiducia_desconto as fd')
            ->join('pessoa as p', 'p.pessoa_id', '=', 'fd.pessoa_id')
            ->whereNotNull('fd.pessoa_id')
            ->when($filters['cliente_id'], fn ($query, $id) => $query->where('fd.pessoa_id', $id))
            ->distinct()
            ->orderBy('p.nome')
            ->pluck('p.nome', 'fd.pessoa_id')
            ->all();

        $bookings = $clients === [] ? collect() : $this->bookings($filters, array_keys($clients));
        $this->loadPending($bookings->where('confirmado', true)->pluck('embarque_id')->all());
        $this->attachProfits($bookings);

        if ($filters['listar_embarques']) {
            $this->attachCovers($bookings);
        }

        $report = [
            'rows' => [], 'grupos' => [],
            'qtd_meses' => $filters['qtd_meses'],
            'agrupar_por_mes' => $filters['agrupar_por_mes'],
            'listar_embarques' => $filters['listar_embarques'],
            'campo_data' => $filters['campo_data'],
        ];

        if (! $filters['agrupar_por_mes']) {
            $byClient = $bookings->groupBy('cliente_id');
            $rows = collect($clients)->map(fn (string $name, int $id) => $this->line($name, $byClient->get($id, collect()), $filters))
                ->when($filters['apenas_com_capa'], fn (Collection $rows) => $rows->filter(fn (array $row) => $row['emb_confirmados'] + $row['emb_nao_confirmados'] > 0));

            return array_merge($report, ['rows' => $rows->values()->all(), 'totais' => $this->line('Total', $bookings, $filters)]);
        }

        $groups = $bookings->groupBy(fn (stdClass $b) => substr($b->data, 0, 7))->sortKeys()->map(function (Collection $month, string $key) use ($clients, $filters) {
            [$year, $number] = array_map('intval', explode('-', $key));
            $byClient = $month->groupBy('cliente_id');
            $monthFilters = ['qtd_meses' => 1] + $filters;

            return [
                'chave' => $key,
                'label' => (self::MONTHS[$number] ?? $number).'/'.$year,
                'rows' => collect($clients)->filter(fn ($name, $id) => $byClient->has($id))
                    ->map(fn (string $name, int $id) => $this->line($name, $byClient->get($id), $monthFilters))->values()->all(),
                'totais' => $this->line('Subtotal', $month, $monthFilters),
            ];
        });

        return array_merge($report, ['grupos' => $groups->values()->all(), 'totais' => $this->line('Total', $bookings, $filters)]);
    }

    /**
     * @param  Filters  $input
     * @return array{ini:string, fim:string, cliente_id:?int, qtd_meses:int, agrupar_por_mes:bool, listar_embarques:bool, apenas_com_capa:bool, campo_data:string, impor_expor:string}
     */
    private function parse(array $input): array
    {
        [$monthStart, $yearStart, $monthEnd, $yearEnd] = array_map('intval', [$input['mes_ini'] ?? 0, $input['ano_ini'] ?? 0, $input['mes_fim'] ?? 0, $input['ano_fim'] ?? 0]);

        if ($monthStart < 1 || $monthStart > 12 || $yearStart < 2013 || $monthEnd < 1 || $monthEnd > 12 || $yearEnd < 2013) {
            throw new DomainException('Informe mês/ano de início e fim válidos.');
        }

        $start = mktime(0, 0, 0, $monthStart, 1, $yearStart);
        $end = mktime(0, 0, 0, $monthEnd + 1, 0, $yearEnd);

        if ($start > $end) {
            throw new DomainException('O período início deve ser anterior ou igual ao período fim.');
        }

        $field = $input['campo_data'] ?? null;
        $type = $input['impor_expor'] ?? '';

        return [
            'ini' => date('Y-m-d', $start),
            'fim' => date('Y-m-d', $end),
            'cliente_id' => (int) ($input['cliente_id'] ?? 0) ?: null,
            'qtd_meses' => ($yearEnd - $yearStart) * 12 + $monthEnd - $monthStart + 1,
            'agrupar_por_mes' => (bool) ($input['agrupar_por_mes'] ?? false),
            'listar_embarques' => (bool) ($input['listar_embarques'] ?? false),
            'apenas_com_capa' => (bool) ($input['apenas_com_capa'] ?? false),
            'campo_data' => array_key_exists($field, self::DATE_FIELDS) ? $field : 'saida_efetiva',
            'impor_expor' => in_array($type, ['i', 'e'], true) ? $type : '',
        ];
    }

    /**
     * Uma linha por reserva: confirmadas (data efetiva no período) e não confirmadas (sem data efetiva, data prevista no período).
     *
     * @param  list<int>  $clientIds
     * @return Collection<int, stdClass>
     */
    private function bookings(array $filters, array $clientIds): Collection
    {
        $actual = $filters['campo_data'];
        $planned = $actual === 'chegada_efetiva' ? 'chegada_prevista' : 'saida_prevista';

        return collect([true, false])->flatMap(fn (bool $confirmed) => DB::table('embarque as e')
            ->whereIn('e.cliente_id', $clientIds)
            ->where('e.finalizado', '<>', 'c')
            ->when($confirmed,
                fn ($query) => $query->whereBetween("e.{$actual}", [$filters['ini'], $filters['fim']]),
                fn ($query) => $query->where(fn ($q) => $q->whereNull("e.{$actual}")->orWhere("e.{$actual}", '0000-00-00'))->whereBetween("e.{$planned}", [$filters['ini'], $filters['fim']]))
            ->when($filters['impor_expor'], fn ($query, $type) => $query->where('e.impor_expor', $type))
            ->when($filters['apenas_com_capa'], fn ($query) => $query->whereExists(fn ($q) => $q->selectRaw('1')->from('capa_processo as cp0')->whereColumn('cp0.embarque_id', 'e.embarque_id')))
            ->selectRaw('e.embarque_id, e.cliente_id, e.booking, e.impor_expor, e.tipo_embarque, e.qtd_bl, e.peso_cotacao, e.volume_cotacao')
            ->selectRaw($confirmed ? "e.{$actual} as data" : "e.{$planned} as data")
            ->selectRaw('EXISTS (SELECT 1 FROM capa_processo cp WHERE cp.embarque_id = e.embarque_id) as tem_capa')
            ->selectRaw('COALESCE(NULLIF((SELECT COUNT(DISTINCT c.container_id) FROM bl INNER JOIN container c ON c.bl_id = bl.bl_id WHERE bl.embarque_id = e.embarque_id), 0),
                                 (SELECT SUM(m.quantidade) FROM mercadoria m WHERE m.embarque_id = e.embarque_id), 0) as equipamentos')
            ->get()
            ->map(fn (stdClass $b) => tap($b, fn (stdClass $b) => $b->confirmado = $confirmed)));
    }

    /** Última Taxa Diária de cada moeda (e a do dólar, que a nota do relatório mostra). */
    private function loadDailyRates(): void
    {
        $this->dailyRates = [1 => 1.0];
        $this->dollarRate = $this->dollarRateDate = null;

        $rows = DB::table('fin_currencies_rates_values as v')
            ->join('fin_currencies_rates as r', 'r.id', '=', 'v.ref_currency_rate')
            ->join('cmn_currencies as c', 'c.id', '=', 'r.ref_currency')
            ->where('r.name', 'like', 'Taxa Diária%')
            ->where('v.rate', '>', 0)
            ->orderByDesc('v.rate_date')->orderByDesc('v.id')
            ->get(['r.ref_currency', 'c.symbol', 'v.rate', 'v.rate_date']);

        foreach ($rows as $row) {
            $this->dailyRates[(int) $row->ref_currency] ??= (float) $row->rate;

            if ($row->symbol === 'US$' && $this->dollarRate === null) {
                [$this->dollarRate, $this->dollarRateDate] = [(float) $row->rate, $row->rate_date];
            }
        }
    }

    /**
     * Receitas e despesas das despesas da reserva que ainda não estão em capa, em R$ pela Taxa Diária. O custo "Due To
     * Agente" não conta quando a reserva tem capa de acerto por notas (receitas ou comissões na capa).
     *
     * @param  list<int>  $bookingIds
     */
    private function loadPending(array $bookingIds): void
    {
        $this->pending = [];

        if ($bookingIds === []) {
            return;
        }

        $rows = DB::table('embarque as e')
            ->join('fin_expenses_values as fev', 'fev.ref_embarque', '=', 'e.embarque_id')
            ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
            ->whereIn('e.embarque_id', $bookingIds)
            ->where(fn ($query) => $query->where('fev.buying_rate', '>', 0)->orWhere('fev.selling_rate', '>', 0))
            ->selectRaw('e.embarque_id, fev.buying_rate, fev.selling_rate, fev.ref_currency_br, fev.ref_currency_sr, fev.ref_due_to')
            ->selectRaw('CASE WHEN fe.ref_unity = 1 THEN COALESCE(NULLIF(e.qtd_bl, 0), 1)
                              WHEN fe.ref_unity = 2 THEN COALESCE((SELECT SUM(m.quantidade) FROM mercadoria m WHERE m.embarque_id = e.embarque_id
                                   AND (fev.ref_equipment IS NULL OR fev.ref_equipment = 0 OR m.tipo_container_id = fev.ref_equipment)), 0)
                              ELSE 1 END as fator')
            ->selectRaw('EXISTS (SELECT 1 FROM capa_processo_receita_item i WHERE i.fin_expenses_values_id = fev.id) as tem_receita_capa')
            ->selectRaw('EXISTS (SELECT 1 FROM capa_processo_despesa_item i WHERE i.fin_expenses_values_id = fev.id) as tem_despesa_capa')
            ->selectRaw('EXISTS (SELECT 1 FROM capa_processo a WHERE a.embarque_id = e.embarque_id AND (COALESCE(a.receitas, 0) <> 0 OR COALESCE(a.comissoes, 0) <> 0)) as tem_acerto_agente')
            ->get();

        foreach ($rows as $row) {
            $line = &$this->pending[(int) $row->embarque_id];
            $line ??= ['receitas' => 0.0, 'despesas' => 0.0, 'provisoria' => false];
            $saleRate = $this->dailyRates[(int) $row->ref_currency_sr] ?? null;
            $buyRate = $this->dailyRates[(int) $row->ref_currency_br] ?? null;

            if (! $row->tem_receita_capa && $row->selling_rate > 0 && $saleRate !== null) {
                $line['receitas'] += $row->selling_rate * $row->fator * $saleRate;
                $line['provisoria'] = true;
            }

            if (! $row->tem_despesa_capa && ! ((int) $row->ref_due_to === 1 && $row->tem_acerto_agente) && $row->buying_rate > 0 && $buyRate !== null) {
                $line['despesas'] += $row->buying_rate * $row->fator * $buyRate;
                $line['provisoria'] = true;
            }
            unset($line);
        }
    }

    /** @param  Collection<int, stdClass>  $bookings */
    private function attachProfits(Collection $bookings): void
    {
        $covers = CapaProcesso::withItemTotals()
            ->whereIn('embarque_id', $bookings->where('confirmado', true)->pluck('embarque_id'))
            ->get()
            ->groupBy('embarque_id')
            ->map(fn (Collection $group) => $group->sum(fn (CapaProcesso $cover) => $cover->coverResult()));

        foreach ($bookings as $booking) {
            $booking->lucro = $booking->confirmado ? $this->confirmedProfit($booking, $covers) : $this->plannedProfit($booking);
        }
    }

    /**
     * @param  Collection<int, float>  $covers
     * @return ?array{valor: float, provisoria: bool, receitas: float, despesas: float}
     */
    private function confirmedProfit(stdClass $booking, Collection $covers): ?array
    {
        $estimate = $this->pending[$booking->embarque_id] ?? ['receitas' => 0.0, 'despesas' => 0.0, 'provisoria' => false];
        $provisional = $estimate['provisoria'];

        if (! $booking->tem_capa && ! $provisional) {
            return null;
        }

        return [
            'valor' => (float) $covers->get($booking->embarque_id, 0) + $estimate['receitas'] - $estimate['despesas'],
            'provisoria' => $provisional,
            'receitas' => $estimate['receitas'],
            'despesas' => $estimate['despesas'],
        ];
    }

    /**
     * Embarque::getTotalizadorExpenses (legado): receita − despesa por Due To e moeda, em R$ pela última taxa da moeda.
     *
     * @return ?array{valor: float, provisoria: bool, receitas: float, despesas: float}
     */
    private function plannedProfit(stdClass $booking): ?array
    {
        $bls = DB::table('bl')->where('embarque_id', $booking->embarque_id)->orderBy('bl_id')->get();
        $buckets = [];

        $expenses = DB::table('fin_expenses_values as v')
            ->leftJoin('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->join('fin_expenses_values_due_to as d', 'd.id', '=', 'v.ref_due_to')
            ->leftJoin('cmn_currencies as cb', 'cb.id', '=', 'v.ref_currency_br')
            ->leftJoin('cmn_currencies as cs', 'cs.id', '=', 'v.ref_currency_sr')
            ->where('v.ref_embarque', $booking->embarque_id)
            ->get(['v.*', 'e.id as expense_id', 'e.ref_unity', 'd.name as due_to_name', 'cb.symbol as br_symbol', 'cs.symbol as sr_symbol']);

        foreach ($expenses as $expense) {
            $factor = $this->totalFactor($expense, $booking, $bls);
            $cost = (float) $expense->buying_rate * $factor;
            $revenue = (float) $expense->selling_rate * $factor;
            $cost = $expense->br_min > 0 && $cost < $expense->br_min ? (float) $expense->br_min : $cost;
            $revenue = $expense->sr_min > 0 && $revenue < $expense->sr_min ? (float) $expense->sr_min : $revenue;
            $buySymbol = $expense->br_symbol;
            $saleSymbol = filled($expense->sr_symbol) ? $expense->sr_symbol : $buySymbol;

            if ($buySymbol === $saleSymbol) {
                $this->addBucket($buckets, $expense->due_to_name, $buySymbol, $expense->ref_currency_br, $cost, $revenue);
            } else {
                if ($cost != 0) {
                    $this->addBucket($buckets, $expense->due_to_name, $buySymbol, $expense->ref_currency_br, $cost, 0);
                }

                if ($revenue != 0) {
                    $this->addBucket($buckets, $expense->due_to_name, $saleSymbol, $expense->ref_currency_sr, 0, $revenue);
                }
            }
        }

        if ($buckets === []) {
            return null;
        }

        $total = $revenues = $costs = 0.0;
        $quotes = [];

        foreach ($buckets as $bucket) {
            $quote = $quotes[$bucket['moeda_id']] ??= $this->latestRate($bucket['moeda_id'], $bucket['symbol']);

            if ($quote !== null) {
                $total += ($bucket['receita'] - $bucket['despesa']) * $quote;
                $revenues += $bucket['receita'] * $quote;
                $costs += $bucket['despesa'] * $quote;
            }
        }

        return ['valor' => $total, 'provisoria' => true, 'receitas' => $revenues, 'despesas' => $costs];
    }

    /** @param  array<string, array{symbol: string, moeda_id: mixed, despesa: float, receita: float}>  $buckets */
    private function addBucket(array &$buckets, string $dueTo, ?string $symbol, mixed $currencyId, float $cost, float $revenue): void
    {
        $symbol = filled($symbol) ? $symbol : '-';
        $bucket = &$buckets["{$dueTo}|{$symbol}"];
        $bucket ??= ['symbol' => $symbol, 'moeda_id' => $currencyId, 'despesa' => 0.0, 'receita' => 0.0];
        $bucket['despesa'] += $cost;
        $bucket['receita'] += $revenue;
    }

    /** Embarque::getUltimaTaxaConversaoReal (legado): real = 1; dólar = última Taxa Diária; demais = última taxa da taxa padrão. */
    private function latestRate(mixed $currencyId, string $symbol): ?float
    {
        if ($symbol === 'R$') {
            return 1.0;
        }

        if (! $currencyId) {
            return null;
        }

        $query = DB::table('fin_currencies_rates_values as v')->join('fin_currencies_rates as r', 'r.id', '=', 'v.ref_currency_rate')
            ->where('r.ref_currency', $currencyId)->where('v.rate', '>', 0)->orderByDesc('v.rate_date')->orderByDesc('v.id');

        $symbol === 'US$'
            ? $query->where('r.name', 'like', 'Taxa Diária%')
            : $query->whereIn('r.id', DB::table('fin_default_rates')->select('ref_currency_rate'));

        $rate = $query->value('v.rate');

        return $rate === null ? null : (float) $rate;
    }

    /**
     * Embarque::getFatorTotalDespesa (legado): soma dos fatores da despesa nos BLs da reserva (ou, sem BL, nas mercadorias).
     *
     * @param  Collection<int, stdClass>  $bls
     */
    private function totalFactor(stdClass $expense, stdClass $booking, Collection $bls): float
    {
        if (! $expense->expense_id) {
            return 1.0;
        }

        if ($bls->isNotEmpty()) {
            $factor = $bls
                ->filter(fn (stdClass $bl) => ! $expense->ref_bl || (int) $expense->ref_bl === (int) $bl->bl_id)
                ->filter(fn (stdClass $bl) => ! $expense->ref_container || (int) DB::table('container')->where('container_id', $expense->ref_container)->value('bl_id') === (int) $bl->bl_id)
                ->sum(fn (stdClass $bl) => $this->expenseLines->factor($expense, $bl));
        } elseif ((int) $expense->ref_unity === self::UNIT_CONTAINER) {
            $factor = (float) DB::table('mercadoria')->where('embarque_id', $booking->embarque_id)
                ->when($expense->ref_equipment, fn ($query, $type) => $query->where('tipo_container_id', $type))->sum('quantidade');
        } elseif ((int) $expense->ref_unity === self::UNIT_WEIGHT_MEASURE) {
            $weight = (float) $booking->peso_cotacao;
            $volume = (float) $booking->volume_cotacao;
            $factor = $booking->impor_expor === 'i'
                ? (($weight || $volume) ? max($booking->tipo_embarque === 'm' ? $volume : $volume / 0.006, $weight) : 0)
                : max($volume / 0.006, $weight);
        } else {
            $factor = 1.0;
        }

        return $factor > 0 ? (float) $factor : 1.0;
    }

    /** Capas (número e arquivo) de cada reserva, para a lista de embarques. */
    private function attachCovers(Collection $bookings): void
    {
        $covers = DB::table('capa_processo as cp')
            ->leftJoin('capa_processo_arquivo as a', 'a.capa_processo_arquivo_id', '=', 'cp.capa_processo_arquivo_id')
            ->whereIn('cp.embarque_id', $bookings->pluck('embarque_id'))
            ->orderBy('cp.numero')
            ->get(['cp.embarque_id', 'cp.capa_process_id as id', 'cp.numero', 'a.nome as arquivo'])
            ->groupBy('embarque_id');

        foreach ($bookings as $booking) {
            $booking->capas = $covers->get($booking->embarque_id, collect())
                ->map(fn (stdClass $c) => ['id' => $c->id, 'numero' => $c->numero, 'arquivo' => filled($c->arquivo) ? $c->arquivo : 'Não Arquivado'])->all();
        }
    }

    /**
     * Uma linha do relatório (cliente, subtotal de mês ou total) a partir das reservas dela.
     *
     * @param  Collection<int, stdClass>  $bookings
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function line(string $name, Collection $bookings, array $filters): array
    {
        $months = max(1, (int) $filters['qtd_meses']);
        [$confirmed, $planned] = [$bookings->where('confirmado', true), $bookings->where('confirmado', false)];
        $profits = $bookings->pluck('lucro')->filter();

        $line = [
            'cliente_nome' => $name,
            'emb_confirmados' => $confirmed->count(),
            'eq_confirmados' => (int) $confirmed->sum('equipamentos'),
            'emb_nao_confirmados' => $planned->count(),
            'eq_nao_confirmados' => (int) $planned->sum('equipamentos'),
            'tem_lucro' => $profits->isNotEmpty(),
            'lucratividade' => $profits->isNotEmpty() ? $profits->sum('valor') : null,
            'lucratividade_provisoria' => $profits->contains('provisoria', true),
            'receitas_estimadas' => $profits->sum('receitas'),
            'despesas_estimadas' => $profits->sum('despesas'),
            'cotacao_dolar' => $this->dollarRate,
            'data_cotacao_dolar' => $this->dollarRateDate,
        ];

        foreach (['emb_confirmados', 'eq_confirmados', 'emb_nao_confirmados', 'eq_nao_confirmados'] as $key) {
            $line["media_{$key}"] = $line[$key] / $months * 1.0;
        }

        $line['detalhes'] = ! $filters['listar_embarques'] ? [] : $bookings->sortBy('booking', SORT_STRING)->map(fn (stdClass $b) => [
            'embarque_id' => $b->embarque_id,
            'booking' => $b->booking,
            'confirmado' => $b->confirmado,
            'equipamentos' => (int) $b->equipamentos,
            'capas' => $b->capas ?? [],
            'tem_lucro' => $b->lucro !== null,
            'lucratividade' => $b->lucro['valor'] ?? null,
            'lucratividade_provisoria' => (bool) ($b->lucro['provisoria'] ?? false),
            'receitas_estimadas' => $b->lucro['receitas'] ?? 0,
            'despesas_estimadas' => $b->lucro['despesas'] ?? 0,
        ])->values()->all();

        return $line;
    }
}
