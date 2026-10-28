<?php

namespace App\Actions\Carriers;

use App\Models\FinExpensesMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * armador/verificaDespesa (legado), chamado a cada despesa nova da matriz.
 *
 * Se há despesa igual ainda vigente (sem `due_to`: mesmo armador-porto, despesa, moeda, tipo, cobrança e, quando a nova
 * tem, equipamento), encerra a antiga com o `due_from` da nova e avisa os embarques abertos que ela atendia. Senão,
 * avisa os embarques abertos que a nova taxa passa a atingir. O e-mail só sai quando há embarques.
 */
class MatrixChangeNotice
{
    private const FINANCE = 'financeiro@dmflog.com.br';

    private const IT = 'chamado@dmflog.com.br';

    private const SECTORS = [
        'i' => ['IMPO', 'importacao@dmflog.com.br'],
        'e' => ['EXPO', 'exportacao@dmflog.com.br'],
    ];

    private const IMPORT_WARNING = '<br/><strong>Note que as taxas portuárias são repasse dos valores cobrados pelas Cias Marítimas e estão sujeitas a alteração/reajuste sem aviso prévio e sem emissão de comunicado pelos armadores.</strong>'
        .'<strong>As taxas de destino (BL Fee, Capatazia, DPP, EHC, ISPS dentre outros) são cobradas de acordo com o valor vigente na data de chegada do container ao porto de descarga.</strong>';

    public function __invoke(FinExpensesMatrix $new, string $userName): void
    {
        $operation = $new->type === 'i' ? 'i' : 'e';
        [$sector, $sectorEmail] = self::SECTORS[$operation];

        $replaced = FinExpensesMatrix::query()
            ->where('ref_armador_porto', $new->ref_armador_porto)
            ->whereNull('due_to')
            ->where('ref_expense', $new->ref_expense)
            ->where('ref_currency', $new->ref_currency)
            ->where('type', $new->type)
            ->where('billing', $new->billing)
            ->whereKeyNot($new->getKey())
            ->when($new->ref_equipamento, fn ($query, $equipment) => $query->where('ref_equipamento', $equipment))
            ->get();

        $replaced->isNotEmpty()
            ? $this->noticeReplaced($new, $replaced, $operation, $sector, $sectorEmail)
            : $this->noticeNew($new, $userName, $operation, $sector, $sectorEmail);
    }

    private function noticeReplaced(FinExpensesMatrix $new, $replaced, string $operation, string $sector, string $sectorEmail): void
    {
        foreach ($replaced as $old) {
            $old->update(['due_to' => $new->due_from]);
        }

        $port = $new->armadorPorto;
        $lines = '';

        foreach ($replaced as $old) {
            $shipments = $this->openShipments($port->ref_armador, $port->ref_porto, $operation, $old->due_to)
                ->join('fin_expenses_values as fev', 'fev.ref_embarque', '=', 'e.embarque_id')
                ->where('fev.ref_expense', $old->ref_expense)
                ->when($old->ref_equipamento, fn ($query, $equipment) => $query->where(fn ($q) => $q->where('fev.ref_equipment', $equipment)->orWhereNull('fev.ref_equipment')))
                ->get();

            $expenseName = $old->expense->name;

            foreach ($shipments as $shipment) {
                $lines .= '<li>'.e($shipment->booking).' ('.e($expenseName).') - '.e($shipment->cliente).'</li>';
            }
        }

        if ($lines === '') {
            return;
        }

        $message = ($operation === 'i'
            ? 'Os seguintes embarques possuem taxas locais que sofreram alterações e precisam ser revisados.'
            : 'Os seguintes embarques possuem taxas de origem que sofreram alterações e precisam ser revisados.')
            ."<br/><ul>{$lines}</ul>".($operation === 'i' ? self::IMPORT_WARNING : '');

        $this->send($sector, $sectorEmail, "Atualizacão de taxas [{$sector}]", $message);
    }

    private function noticeNew(FinExpensesMatrix $new, string $userName, string $operation, string $sector, string $sectorEmail): void
    {
        $port = $new->armadorPorto;

        $shipments = $this->openShipments($port->ref_armador, $port->ref_porto, $operation, $new->due_from)
            ->join('mercadoria as m', 'm.embarque_id', '=', 'e.embarque_id')
            ->when($new->ref_equipamento, fn ($query, $equipment) => $query->where('m.tipo_container_id', $equipment))
            ->get();

        if ($shipments->isEmpty()) {
            return;
        }

        $expenseLabel = $new->expense->label;
        $lines = $shipments->map(fn ($shipment) => '<li>'.e($shipment->booking).' ('.e($expenseLabel).') - '.e($shipment->cliente).'</li>')->implode('');

        $message = 'Uma nova taxa foi cadastrada na Matriz do Despesas pelo usuario '.e($userName)." e os seguintes embarques precisam ser revisados.<br/><ul>{$lines}</ul>"
            .($operation === 'i' ? self::IMPORT_WARNING : '');

        $this->send($sector, $sectorEmail, "Atualizacão de taxas [{$sector}]: Nova despesa Armador ".$port->armador->nome, $message);
    }

    /** Embarques master abertos do armador no porto (destino na importação, origem na exportação) com previsão a partir da data. */
    private function openShipments(int $carrierId, int $portId, string $operation, string $fromDate)
    {
        [$portColumn, $dateColumn] = $operation === 'i'
            ? ['porto_destino_id', 'chegada_prevista']
            : ['porto_origem_id', 'saida_prevista'];

        return DB::table('embarque as e')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->where('e.armador_id', $carrierId)
            ->where("e.{$portColumn}", $portId)
            ->where("e.{$dateColumn}", '>=', $fromDate)
            ->where('e.finalizado', '<>', 'c')
            ->where('e.impor_expor', $operation)
            ->where('e.tipo_embarque', 'm')
            ->distinct()
            ->select('e.embarque_id', 'e.booking', 'p.nome as cliente');
    }

    private function send(string $sector, string $sectorEmail, string $subject, string $message): void
    {
        Mail::html($message, fn ($mail) => $mail
            ->to([$sectorEmail, self::FINANCE, self::IT])
            ->subject($subject));
    }
}
