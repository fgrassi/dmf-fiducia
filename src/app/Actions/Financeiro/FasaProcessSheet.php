<?php

namespace App\Actions\Financeiro;

use App\Enums\Cobranca\ChargeStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * financeiro/planilhaFasa (legado), "Planilha Processos FASA": cobranças (Normal, Extra, Demurrage e Detention) de um
 * cliente/exportador/importador ou grupo de empresas, separadas em Embarques sem boleto, Taxas diversas e Detention.
 * Sem cliente nem grupo não consulta nada (a base inteira não cabe na tela).
 *
 * @phpstan-type Filters array{cliente?:?string, grupo_empresa_id?:int|string|null, impor_expor?:?string, tipo_embarque?:?string, venc_ini?:?string, venc_fim?:?string, baixa_ini?:?string, baixa_fim?:?string, status?:int|string|null}
 */
class FasaProcessSheet
{
    public const NORMAL = 1;

    public const EXTRA = 2;

    public const DEMURRAGE = 3;

    public const DETENTION = 4;

    public const TYPES = [self::NORMAL => 'Normal', self::EXTRA => 'Extra', self::DEMURRAGE => 'Demurrage', self::DETENTION => 'Detention'];

    /** @return array<string, array{title: string, total_label: string, has_taxa: bool, rows: list<array<string, mixed>>, total: float}> */
    public function __invoke(array $filters = []): array
    {
        $sections = [
            'embarques' => ['title' => 'EMBARQUES SEM BOLETO', 'total_label' => 'TOTAL EMBARQUES SEM BOLETO', 'has_taxa' => false, 'rows' => [], 'total' => 0.0],
            'taxas' => ['title' => 'TAXAS DIVERSAS', 'total_label' => 'TOTAL TAXAS DIVERSAS', 'has_taxa' => true, 'rows' => [], 'total' => 0.0],
            'detention' => ['title' => 'DETENTION', 'total_label' => 'TOTAL DETENTION', 'has_taxa' => true, 'rows' => [], 'total' => 0.0],
        ];

        if (trim((string) ($filters['cliente'] ?? '')) === '' && empty($filters['grupo_empresa_id'])) {
            return $sections;
        }

        $charges = $this->charges($filters);
        $expenses = $this->expenseNames($charges->pluck('id')->all());

        foreach ($charges as $charge) {
            $names = $expenses[$charge->id] ?? [];
            $section = $this->section((int) $charge->ref_type, $names);
            $row = $this->row($charge, $names);

            $sections[$section]['rows'][] = $row;
            $sections[$section]['total'] += $row['valor'];
        }

        return $sections;
    }

    /** pc = cliente, pe = shipper (exportador), pi = consignee (importador). */
    private function charges(array $filters)
    {
        $search = trim((string) ($filters['cliente'] ?? ''));
        $dueIni = $filters['venc_ini'] ?? null;
        $dueEnd = $filters['venc_fim'] ?? null;

        return DB::table('fin_charges as f')
            ->leftJoin('embarque as e', 'e.embarque_id', '=', 'f.ref_booking')
            ->leftJoin('bl as b', 'b.bl_id', '=', 'f.ref_bl')
            ->leftJoin('pessoa as pc', 'pc.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('pessoa as pe', 'pe.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->leftJoin('pessoa as pi', 'pi.pessoa_id', '=', 'e.pessoa_id_importador')
            ->select('f.id', 'f.ref_type', 'f.status', 'f.total_value', 'f.due_date', 'f.payment_date', 'f.created_at', 'f.obs')
            ->addSelect('e.booking', 'e.invoice', 'e.saida_efetiva', 'b.dmf_ref')
            ->selectRaw('pc.nome as cliente_nome, pe.nome as shipper_nome, pi.nome as consignee_nome')
            ->whereIn('f.ref_type', array_keys(self::TYPES))
            ->when($filters['grupo_empresa_id'] ?? null, fn ($query, $group) => $query->whereExists(
                fn ($member) => $member->selectRaw('1')->from('grupo_empresa_membro as gem')
                    ->whereColumn('gem.pessoa_id', 'pc.pessoa_id')->where('gem.grupo_empresa_id', $group),
            ))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('pc.nome', 'like', "%{$search}%")->orWhere('pe.nome', 'like', "%{$search}%")->orWhere('pi.nome', 'like', "%{$search}%")))
            ->when($filters['impor_expor'] ?? null, fn ($query, $value) => $query->where('e.impor_expor', $value))
            ->when($filters['tipo_embarque'] ?? null, fn ($query, $value) => $query->where('e.tipo_embarque', $value))
            ->when(($filters['status'] ?? '') !== '' && ($filters['status'] ?? null) !== null, fn ($query) => $query->where('f.status', (int) $filters['status']))
            // Vencimento efetivo: o vencimento, ou a criação quando ele está vazio.
            ->when($dueIni, fn ($query) => $query->where(fn ($q) => $q->where('f.due_date', '>=', $dueIni)->orWhere(fn ($q) => $q->whereNull('f.due_date')->where('f.created_at', '>=', $dueIni.' 00:00:00'))))
            ->when($dueEnd, fn ($query) => $query->where(fn ($q) => $q->where('f.due_date', '<=', $dueEnd)->orWhere(fn ($q) => $q->whereNull('f.due_date')->where('f.created_at', '<=', $dueEnd.' 23:59:59'))))
            ->when($filters['baixa_ini'] ?? null, fn ($query, $date) => $query->where('f.payment_date', '>=', $date))
            ->when($filters['baixa_fim'] ?? null, fn ($query, $date) => $query->where('f.payment_date', '<=', $date))
            ->orderBy('f.due_date')
            ->orderBy('f.created_at')
            ->orderBy('f.id')
            ->get();
    }

    /** @return array<int, list<string>> */
    private function expenseNames(array $chargeIds): array
    {
        $names = [];

        foreach (array_chunk($chargeIds, 500) as $chunk) {
            $items = DB::table('fin_charges_items as i')
                ->join('fin_expenses_values as fev', 'fev.id', '=', 'i.ref_expense')
                ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
                ->whereIn('i.ref_charge', $chunk)
                ->get(['i.ref_charge', 'fe.name']);

            foreach ($items as $item) {
                $name = trim((string) $item->name);

                if ($name !== '' && ! in_array($name, $names[$item->ref_charge] ?? [], true)) {
                    $names[$item->ref_charge][] = $name;
                }
            }
        }

        return $names;
    }

    /** @param  list<string>  $names */
    private function section(int $type, array $names): string
    {
        if (in_array($type, [self::DEMURRAGE, self::DETENTION], true) || preg_match('/detention|demurrage/i', implode(' ', $names))) {
            return 'detention';
        }

        return $type === self::EXTRA ? 'taxas' : 'embarques';
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private function row(stdClass $charge, array $names): array
    {
        $type = (int) $charge->ref_type;
        $status = (int) $charge->status;
        $due = $charge->due_date ? Carbon::parse($charge->due_date)->startOfDay() : null;
        $created = $charge->created_at ? Carbon::parse($charge->created_at) : null;
        $effective = $due ?? $created?->copy()->startOfDay();
        $payment = $charge->payment_date ? Carbon::parse($charge->payment_date)->startOfDay() : null;

        return [
            'id' => (int) $charge->id,
            'empresa' => (string) $charge->cliente_nome,
            'shipper' => (string) $charge->shipper_nome,
            'consignee' => (string) $charge->consignee_nome,
            'booking' => (string) $charge->booking,
            'tipo_cobranca' => self::TYPES[$type] ?? '',
            // total_value já inclui o IOF quando houver.
            'valor' => (float) $charge->total_value,
            'fatura' => (string) ($charge->dmf_ref ?: $charge->invoice),
            'saida_efetiva' => $charge->saida_efetiva ? Carbon::parse($charge->saida_efetiva)->format('d/m/Y') : '',
            'vencimento' => $effective?->format('d/m/Y') ?? (in_array($type, [self::DEMURRAGE, self::DETENTION], true) ? 'Imediato' : 'Na apresentação'),
            'editable' => $status === ChargeStatus::Open->value,
            'situacao' => $this->situation($status, $effective),
            'num_cobranca' => $charge->id.'/'.($created ?? now())->format('Y'),
            'taxa' => implode(' + ', $names),
            'data_pagamento' => $payment?->format('d/m/Y') ?? '',
            'atraso' => $this->delay($status, $effective, $payment),
            'obs' => (string) $charge->obs,
        ];
    }

    private function situation(int $status, ?Carbon $effectiveDue): string
    {
        if ($status === ChargeStatus::Open->value) {
            return $effectiveDue && $effectiveDue->lt(today()) ? 'vencido' : 'Aberto';
        }

        return ChargeStatus::tryFrom($status)?->label() ?? 'Aberto';
    }

    private function delay(int $status, ?Carbon $effectiveDue, ?Carbon $payment): string
    {
        $end = $payment ?? ($status === ChargeStatus::Open->value ? today() : null);

        if (! $effectiveDue || ! $end) {
            return '';
        }

        $days = (int) $effectiveDue->diffInDays($end, false);

        return match (true) {
            $days <= 0 => 'pago em dia',
            $days === 1 => '1 dia de atraso',
            default => "{$days} dias de atraso",
        };
    }
}
