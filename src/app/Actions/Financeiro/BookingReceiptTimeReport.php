<?php

namespace App\Actions\Financeiro;

use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * relatorioTempoRecebimento (legado), "Tempo Booking × Recebimento": por reserva (exceto tipo `s`), dias entre a criação
 * (`data_topo`) e a primeira baixa de cobrança (menor `payment_date` das cobranças não canceladas), com a primeira baixa
 * dentro do período. Mostra prazo e boleto negociados do cliente (`fin_clients_boleto`), agrupa por cliente e/ou operação
 * e dá a média de dias (geral, IMPO e EXPO).
 *
 * @phpstan-type Filters array{mes_ini:int|string|null, ano_ini:int|string|null, mes_fim:int|string|null, ano_fim:int|string|null, impor_expor?:?string, mostrar_lista?:bool, agrupar_por?:?string, com_prazo_negociado?:bool, com_boleto?:bool, ordenar_por?:?string, ordenar_dir?:?string}
 */
class BookingReceiptTimeReport
{
    public const MONTHS = [1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARCO', 4 => 'ABRIL', 5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO', 9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO'];

    public const TYPES = ['i' => 'IMPO', 'e' => 'EXPO'];

    public const GROUPS = ['cliente' => 'Por cliente', 'operacao' => 'Por operação (IMPO/EXPO)', 'cliente_operacao' => 'Por cliente e operação'];

    public const SORTS = ['cliente' => 'Cliente', 'operacoes' => 'Operações', 'media' => 'Média'];

    /**
     * @param  Filters  $input
     * @return array{rows: list<array<string, mixed>>, grupos: list<array<string, mixed>>, medias: array<string, array{count: int, media: ?float}>, mostrar_lista: bool, agrupar_por: string}
     *
     * @throws DomainException filtro inválido (mesmas mensagens do legado)
     */
    public function __invoke(array $input): array
    {
        [$start, $end] = $this->period($input);

        $type = in_array($input['impor_expor'] ?? '', array_keys(self::TYPES), true) ? $input['impor_expor'] : '';
        $groupBy = array_key_exists($input['agrupar_por'] ?? '', self::GROUPS) ? $input['agrupar_por'] : '';
        $sortBy = array_key_exists($input['ordenar_por'] ?? '', self::SORTS) ? $input['ordenar_por'] : 'cliente';
        $desc = strtolower((string) ($input['ordenar_dir'] ?? 'asc')) === 'desc';

        $rows = $this->rows($start->toDateString(), $end->toDateString(), $type, (bool) ($input['com_prazo_negociado'] ?? false), (bool) ($input['com_boleto'] ?? false));
        $rows = $this->sortRows($rows, $sortBy, $desc);

        return [
            'rows' => $rows,
            'grupos' => $this->groups($rows, $groupBy, $sortBy, $desc),
            'medias' => $this->averages($rows),
            'mostrar_lista' => (bool) ($input['mostrar_lista'] ?? true),
            'agrupar_por' => $groupBy,
        ];
    }

    /**
     * @param  Filters  $input
     * @return array{Carbon, Carbon} primeiro e último dia do período
     *
     * @throws DomainException filtro inválido (mesmas mensagens do legado)
     */
    public function period(array $input): array
    {
        [$monthStart, $yearStart, $monthEnd, $yearEnd] = array_map('intval', [$input['mes_ini'] ?? 0, $input['ano_ini'] ?? 0, $input['mes_fim'] ?? 0, $input['ano_fim'] ?? 0]);

        if ($monthStart < 1 || $monthStart > 12 || $yearStart < 2013 || $monthEnd < 1 || $monthEnd > 12 || $yearEnd < 2013) {
            throw new DomainException('Informe mês/ano de início e fim válidos.');
        }

        $start = Carbon::create($yearStart, $monthStart, 1)->startOfDay();
        $end = Carbon::create($yearEnd, $monthEnd, 1)->endOfMonth()->startOfDay();

        if ($start->gt($end)) {
            throw new DomainException('O período início deve ser anterior ou igual ao período fim.');
        }

        return [$start, $end];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $start, string $end, string $type, bool $withTerm, bool $withBoleto): array
    {
        $query = DB::table('fin_charges as f')
            ->join('embarque as e', 'e.embarque_id', '=', 'f.ref_booking')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('fin_clients_boleto as fcb', 'fcb.ref_client', '=', 'e.cliente_id')
            ->whereNotNull('f.payment_date')
            ->where('f.status', '<>', 0)
            ->where('e.tipo_embarque', '<>', 's')
            ->when($type !== '', fn ($query) => $query->where('e.impor_expor', $type))
            ->when($withTerm, fn ($query) => $this->negotiated($query, 'prazo', $type))
            ->when($withBoleto, fn ($query) => $this->negotiated($query, 'boleto', $type))
            ->groupBy('e.embarque_id', 'e.booking', 'e.data_topo', 'e.impor_expor', 'e.cliente_id', 'p.nome', 'fcb.prazo_imp', 'fcb.prazo_exp', 'fcb.boleto_imp', 'fcb.boleto_exp')
            ->havingRaw('MIN(f.payment_date) BETWEEN ? AND ?', [$start, $end])
            ->orderBy('e.impor_expor')
            ->orderBy('e.booking')
            ->selectRaw('e.embarque_id, e.booking, e.data_topo, e.impor_expor, e.cliente_id, p.nome as cliente_nome, fcb.prazo_imp, fcb.prazo_exp, fcb.boleto_imp, fcb.boleto_exp, MIN(f.payment_date) as primeira_baixa');

        return $query->get()->map(function ($row) {
            $row = (array) $row;
            // DATEDIFF do legado: só a data, negativo se a baixa veio antes da criação; sem data_topo não há dias.
            $row['dias'] = filled($row['data_topo']) ? (int) Carbon::parse($row['data_topo'])->startOfDay()->diffInDays(Carbon::parse($row['primeira_baixa'])->startOfDay(), false) : null;

            return $row;
        })->all();
    }

    /** Cliente com prazo (ou boleto) negociado na operação filtrada, ou na da própria reserva quando o tipo é "Todos". */
    private function negotiated($query, string $kind, string $type): void
    {
        $imp = "COALESCE(fcb.{$kind}_imp, 0) > 0";
        $exp = "COALESCE(fcb.{$kind}_exp, 0) > 0";

        $query->whereRaw(match ($type) {
            'i' => $imp,
            'e' => $exp,
            default => "((e.impor_expor = 'i' AND {$imp}) OR (e.impor_expor = 'e' AND {$exp}))",
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, string $sortBy, bool $desc): array
    {
        $byClient = fn (array $a, array $b) => strcasecmp((string) $a['cliente_nome'], (string) $b['cliente_nome']);

        usort($rows, function (array $a, array $b) use ($sortBy, $desc, $byClient) {
            $cmp = match ($sortBy) {
                'operacoes' => strcmp((string) $a['impor_expor'], (string) $b['impor_expor']) ?: $byClient($a, $b),
                'media' => ((int) $a['dias'] <=> (int) $b['dias']) ?: $byClient($a, $b),
                default => $byClient($a, $b),
            };

            return ($cmp ?: strcasecmp((string) $a['booking'], (string) $b['booking'])) * ($desc ? -1 : 1);
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groups(array $rows, string $groupBy, string $sortBy, bool $desc): array
    {
        if ($groupBy === '') {
            return [];
        }

        $groups = [];

        foreach ($rows as $row) {
            $client = filled($row['cliente_nome']) ? $row['cliente_nome'] : '(Sem cliente)';
            $type = self::TYPES[$row['impor_expor']] ?? (string) $row['impor_expor'];

            [$key, $label] = match ($groupBy) {
                'cliente' => ['c:'.$row['cliente_id'], $client],
                'operacao' => ['o:'.$row['impor_expor'], $type],
                default => ['co:'.$row['cliente_id'].':'.$row['impor_expor'], "{$client} — {$type}"],
            };

            $groups[$key]['label'] = $label;
            $groups[$key]['rows'][] = $row;
            $groups[$key]['dias'] = [...($groups[$key]['dias'] ?? []), ...($row['dias'] === null ? [] : [$row['dias']])];
        }

        $groups = array_map(fn (array $group) => [
            'label' => $group['label'],
            'rows' => $group['rows'],
            'count' => count($group['dias']),
            'media' => $group['dias'] === [] ? null : round(array_sum($group['dias']) / count($group['dias']), 1),
        ], array_values($groups));

        usort($groups, function (array $a, array $b) use ($sortBy, $desc) {
            $cmp = match ($sortBy) {
                'operacoes' => $a['count'] <=> $b['count'],
                // Grupo sem média vai sempre para o fim, em qualquer ordem.
                'media' => $a['media'] === null || $b['media'] === null ? ($a['media'] === null) <=> ($b['media'] === null) : $a['media'] <=> $b['media'],
                default => strcasecmp($a['label'], $b['label']),
            };

            return ($cmp ?: strcasecmp($a['label'], $b['label'])) * ($desc ? -1 : 1);
        });

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{geral: array{count: int, media: ?float}, impo: array{count: int, media: ?float}, expo: array{count: int, media: ?float}}
     */
    private function averages(array $rows): array
    {
        $days = ['geral' => [], 'impo' => [], 'expo' => []];

        foreach ($rows as $row) {
            if ($row['dias'] === null) {
                continue;
            }

            $days['geral'][] = $row['dias'];

            if (in_array($row['impor_expor'], ['i', 'e'], true)) {
                $days[$row['impor_expor'] === 'i' ? 'impo' : 'expo'][] = $row['dias'];
            }
        }

        return array_map(fn (array $values) => [
            'count' => count($values),
            'media' => $values === [] ? null : round(array_sum($values) / count($values), 1),
        ], $days);
    }

    /** Prazo do cliente em dias; "-" quando não há. */
    public static function term(mixed $days): string
    {
        return (int) $days > 0 ? (string) (int) $days : '-';
    }

    public static function boleto(mixed $flag): string
    {
        return (int) $flag ? 'SIM' : 'NÃO';
    }
}
