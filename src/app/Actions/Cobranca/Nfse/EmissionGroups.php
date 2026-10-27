<?php

namespace App\Actions\Cobranca\Nfse;

use App\Models\Embarque;
use App\Models\FinCharges;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * NfseEmissaoService::montarGruposEmissao (legado): fatia os itens da IC pelas operações de NFS-e do modal
 * (config_nfse_operacao + despesas vinculadas). Cada grupo vira um recibo e uma NFS-e.
 * Itens sem operação vão para o grupo "residual" (operação do modal sem despesas, ex.: 990101), que leva o IOF;
 * sem residual, o IOF vai no último grupo. O desconto é rateado pela proporção de cada grupo.
 */
class EmissionGroups
{
    /** @return list<array{operation_id:int, item_ids:list<int>, total:float, residual:bool}> vazio = recibo único */
    public function __invoke(FinCharges $charge): array
    {
        $mode = $charge->embarque?->impor_expor;

        if (! in_array($mode, ['e', 'i'], true)) {
            return [];
        }

        $operations = DB::table('config_nfse_operacao')->where('impor_expor', $mode)->where('ativo', 1)
            ->orderBy('ordem')->orderBy('codigo_operacao')->pluck('config_nfse_operacao_id');
        $links = DB::table('config_nfse_operacao_despesa')->whereIn('config_nfse_operacao_id', $operations)
            ->get()->groupBy('config_nfse_operacao_id')->map(fn ($l) => $l->pluck('fin_expenses_id')->map(fn ($id) => (int) $id)->all());

        if ($links->isEmpty()) {
            return [];
        }

        $items = DB::table('fin_charges_items as i')->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->where('i.ref_charge', $charge->id)->get(['i.id', 'i.item_value', 'v.ref_expense'])
            ->mapWithKeys(fn ($i) => [(int) $i->id => ['expense' => (int) $i->ref_expense, 'value' => (float) $i->item_value]]);

        if ($items->isEmpty()) {
            return [];
        }

        $itemsTotal = $items->sum('value');
        $discount = (float) $charge->discount;
        $share = fn (float $sum) => max(0.0, round($sum - ($discount > 0 && $itemsTotal > 0 ? round($discount * ($sum / $itemsTotal), 2) : 0.0), 2));
        $used = [];
        $groups = [];

        foreach ($operations as $operationId) {
            $linked = array_flip($links[$operationId] ?? []);
            $ids = $items->filter(fn ($i, $id) => ! isset($used[$id]) && isset($linked[$i['expense']]))->keys()->all();

            if ($ids === []) {
                continue;
            }

            $used += array_fill_keys($ids, true);
            $groups[] = ['operation_id' => (int) $operationId, 'item_ids' => $ids, 'total' => $share($items->only($ids)->sum('value')), 'residual' => false];
        }

        if ($groups === []) {
            return [];
        }

        $iof = (float) $charge->valor_iof;
        $rest = $items->except(array_keys($used));

        if ($rest->isNotEmpty()) {
            $default = $operations->first(fn ($id) => ! $links->has($id));

            if (! $default) {
                throw new DomainException('Há despesas sem vínculo a operação NFSe, mas não existe operação do modal '
                    .strtoupper($mode).' sem despesas vinculadas (ex.: 990101).');
            }

            $groups[] = ['operation_id' => (int) $default, 'item_ids' => $rest->keys()->all(),
                'total' => round($share($rest->sum('value')) + max(0.0, $iof), 2), 'residual' => true];
        } elseif ($iof > 0) {
            $groups[array_key_last($groups)]['total'] = round($groups[array_key_last($groups)]['total'] + $iof, 2);
        }

        return $groups;
    }

    /**
     * NfseEmissaoService::emiteNfseComInstrucao: a NFS-e sai junto com a IC quando o armador não recalcula
     * (exceto aéreo/seguro) ou o cliente tem prazo/boleto no modal.
     */
    public static function issuedWithInstruction(Embarque $embarque): bool
    {
        if (self::carrierDoesNotRecalculate($embarque)) {
            return true;
        }

        $term = $embarque->cliente_id > 0 ? DB::table('fin_clients_boleto')->where('ref_client', $embarque->cliente_id)->first() : null;

        return (bool) $term && match ($embarque->impor_expor) {
            'i' => (int) $term->prazo_imp > 0 || (int) $term->boleto_imp === 1 || (int) $term->prazo > 0,
            'e' => (int) $term->prazo_exp > 0 || (int) $term->boleto_exp === 1 || (int) $term->prazo > 0,
            default => false,
        };
    }

    /** NfseEmissaoService::armadorNaoRecalcula: armador com recalcular = 0 (aéreo/seguro nunca). */
    public static function carrierDoesNotRecalculate(Embarque $embarque): bool
    {
        $flag = $embarque->armador_id ? DB::table('armador')->where('armador_id', $embarque->armador_id)->value('recalcular') : null;

        return ! in_array($embarque->tipo_embarque, ['a', 's'], true) && $flag !== null && (int) $flag === 0;
    }
}
