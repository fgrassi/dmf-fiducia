<?php

namespace App\Actions\Brokers;

use App\Enums\Alert;
use App\Models\DespachantePorto;
use App\Models\MatrizDespesaDespachante;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * despachante/saveExpense (legado): grava a despesa da matriz do despachante no porto. As outras despesas vigentes iguais
 * (mesmo porto, despesa, moeda, tipo, cobrança e, quando há, equipamento) são encerradas com o início da validade desta, e os
 * embarques abertos do despachante que usam a despesa vão por e-mail para revisão (importação e exportação, cada uma ao seu setor).
 *
 * Como no legado, a exportação também compara com a chegada prevista.
 */
class SaveBrokerExpense
{
    private const IMPORT_WARNING = '<br/><strong>Note que as taxas portuárias são repasse dos valores cobrados pelas Cias Marítimas e estão sujeitas a alteração/reajuste sem aviso prévio e sem emissão de comunicado pelos armadores.</strong>'
        .'<strong>As taxas de destino (BL Fee, Capatazia, DPP, EHC, ISPS dentre outros) são cobradas de acordo com o valor vigente na data de chegada do container ao porto de descarga.</strong>';

    /** @param  array<string, mixed>  $data */
    public function __invoke(DespachantePorto $brokerPort, array $data, User $user, ?MatrizDespesaDespachante $expense = null): MatrizDespesaDespachante
    {
        $expense ??= new MatrizDespesaDespachante([
            'ref_despachante_porto' => $brokerPort->getKey(),
            'created_at' => today(),
            'created_by' => $user->getKey(),
        ]);

        if ($expense->exists) {
            $expense->updated_at = today();
            $expense->updated_by = $user->getKey();
        }

        $expense->fill([...$data, 'ref_equipamento' => $data['ref_equipamento'] ?: null, 'due_to' => $data['due_to'] ?: null])->save();

        $replaced = MatrizDespesaDespachante::query()
            ->where('ref_despachante_porto', $brokerPort->getKey())
            ->whereNull('due_to')
            ->where('ref_expense', $expense->ref_expense)
            ->where('ref_currency', $expense->ref_currency)
            ->where('type', $expense->type)
            ->where('billing', $expense->billing)
            ->whereKeyNot($expense->getKey())
            ->when($expense->ref_equipamento, fn ($query, $equipment) => $query->where('ref_equipamento', $equipment))
            ->get();

        foreach ($replaced as $old) {
            $old->update(['due_to' => $expense->due_from]);
        }

        if ($replaced->isNotEmpty() && in_array($expense->type, ['i', 'e'], true)) {
            $this->notify($brokerPort, $replaced, $expense->type);
        }

        return $expense;
    }

    /** @param  Collection<int, MatrizDespesaDespachante>  $replaced */
    private function notify(DespachantePorto $brokerPort, $replaced, string $type): void
    {
        $lines = '';

        foreach ($replaced as $old) {
            $bookings = DB::table('embarque as e')
                ->join('fin_expenses_values as fev', 'fev.ref_embarque', '=', 'e.embarque_id')
                ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
                ->where('e.despachante_id', $brokerPort->id_despachante)
                ->where($type === 'i' ? 'e.porto_destino_id' : 'e.porto_origem_id', $brokerPort->id_porto)
                ->where('fev.ref_expense', $old->ref_expense)
                ->where('e.chegada_prevista', '>=', $old->due_to)
                ->where('e.finalizado', '<>', 'c')
                ->distinct()
                ->select('e.embarque_id', 'e.booking', 'p.nome as cliente')
                ->get();

            foreach ($bookings as $booking) {
                $lines .= '<li>'.e($booking->booking).' ('.e($old->expense->name).') - '.e($booking->cliente).'</li>';
            }
        }

        if ($lines === '') {
            return;
        }

        $message = "Os seguintes embarques possuem taxas locais que sofreram alteracões e precisam ser revisados.<br/><ul>{$lines}</ul>"
            .($type === 'i' ? self::IMPORT_WARNING : '');

        ($type === 'i' ? Alert::BrokerRatesImport : Alert::BrokerRatesExport)->send('Atualizacão de taxas', $message);
    }
}
