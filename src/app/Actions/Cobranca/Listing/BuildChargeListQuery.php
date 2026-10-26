<?php

namespace App\Actions\Cobranca\Listing;

use App\Enums\Cobranca\ChargeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Port de buildListQuery (cobranca/actions): busca + "exibir" (abertos/pagos/todos), agora com bindings. */
class BuildChargeListQuery
{
    /** Opções do filtro de NFS-e (FinChargesNfse::opcoesFiltroStatus). */
    public const NFSE_OPTIONS = [
        'nao_gerada' => 'Não gerada', 'autorizada' => 'Autorizada', 'enfileirada' => 'Enfileirada', 'processando' => 'Processando',
        'rejeitada' => 'Rejeitada', 'erro' => 'Erro', 'cancelada' => 'Cancelada', 'substituida' => 'Substituída', 'rascunho' => 'Rascunho',
    ];

    public function applyShow(Builder $query, string $show): Builder
    {
        return match ($show) {
            'abertos' => $query->where('fin_charges.status', ChargeStatus::Open->value),
            'pagos' => $query->where('fin_charges.status', '<>', ChargeStatus::Open->value),
            default => $query,
        };
    }

    /** @param  array<string, mixed>  $f  chaves do FinChargesFormFilter */
    public function applyFilters(Builder $query, array $f): Builder
    {
        $f = array_filter($f, fn ($v) => $v !== null && $v !== '');

        $query
            ->when($f['created_at'] ?? null, fn ($q, $v) => $q->whereDate('fin_charges.created_at', $v))
            ->when($f['data_ini'] ?? null, fn ($q, $v) => $q->whereDate('fin_charges.payment_date', '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->whereDate('fin_charges.payment_date', '<=', $v))
            ->when($f['venc_ini'] ?? null, fn ($q, $v) => $q->whereDate('fin_charges.due_date', '>=', $v))
            ->when($f['venc_fim'] ?? null, fn ($q, $v) => $q->whereDate('fin_charges.due_date', '<=', $v))
            ->when($f['ref_type'] ?? null, fn ($q, $v) => $q->where('fin_charges.ref_type', $v))
            ->when($f['id_cobranca'] ?? null, fn ($q, $v) => $q->where('fin_charges.id', $v))
            ->when($f['id_fpr'] ?? null, fn ($q, $v) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('fin_payment_receipt as r')
                ->whereColumn('r.ref_charge', 'fin_charges.id')->where('r.id', $v)))
            ->when($f['num_nota_fiscal'] ?? null, fn ($q, $v) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('fin_charges_arquivo_nota as a')
                ->whereColumn('a.cobranca_id', 'fin_charges.id')->where('a.numero_nota_fiscal', $v)))
            ->when($f['booking'] ?? null, fn ($q, $v) => $q->whereHas('embarque', fn ($e) => $e->where(fn ($e) => $e
                ->where('booking', 'like', "%{$v}%")->orWhere('invoice', 'like', "%{$v}%"))))
            ->when($f['hbl'] ?? null, fn ($q, $v) => $q->whereHas('embarque', fn ($e) => $e->where('house_bl', 'like', "%{$v}%")))
            ->when($f['cliente'] ?? null, fn ($q, $v) => $q->whereHas('embarque.cliente', fn ($p) => $p->where('nome', 'like', "%{$v}%")))
            ->when($f['grupo_empresa_id'] ?? null, fn ($q, $v) => $q->whereHas('embarque', fn ($e) => $e->whereExists(
                fn ($m) => $m->selectRaw('1')->from('grupo_empresa_membro as gem')
                    ->whereColumn('gem.pessoa_id', 'embarque.cliente_id')->where('gem.grupo_empresa_id', $v),
            )));

        if (isset($f['valor_total'])) {
            // aceita "1.234,56" (digitado) ou "1234.56"
            $value = str_contains($f['valor_total'], ',') ? str_replace(['.', ','], ['', '.'], $f['valor_total']) : $f['valor_total'];
            $query->where('fin_charges.total_value', $value);
        }

        if (isset($f['status'])) {
            $query->where('fin_charges.status', (int) $f['status']);
        }

        if (isset($f['status_nfse'])) {
            $this->applyNfseStatus($query, (string) $f['status_nfse']);
        }

        return $query;
    }

    /** aplicarFiltroStatusListagem: "erro" agrupa erro + erro_http; com NFS-e autorizada só vale o filtro "autorizada". */
    private function applyNfseStatus(Builder $query, string $status): void
    {
        if (! array_key_exists($status, self::NFSE_OPTIONS)) {
            return;
        }

        $nfse = fn () => DB::table('fin_charges_nfse as nf')->selectRaw('1')->whereColumn('nf.ref_charge', 'fin_charges.id');

        match ($status) {
            'nao_gerada' => $query->whereNotExists($nfse()),
            'autorizada' => $query->whereExists($nfse()->where('nf.status', 'autorizada')),
            default => $query
                ->whereNotExists($nfse()->where('nf.status', 'autorizada'))
                ->whereExists($nfse()
                    ->whereRaw('nf.fin_charges_nfse_id = (select max(n2.fin_charges_nfse_id) from fin_charges_nfse n2 where n2.ref_charge = fin_charges.id)')
                    ->whereIn('nf.status', $status === 'erro' ? ['erro', 'erro_http'] : [$status])),
        };
    }
}
