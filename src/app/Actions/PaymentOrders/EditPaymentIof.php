<?php

namespace App\Actions\PaymentOrders;

use App\Actions\Booking\BookingLog;
use App\Models\FinPaymentOrder;
use App\Models\InstrucaoPagamento;
use App\Models\InstrucaoPagamentoOutros;
use App\Models\OrdemPagamento;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Edição do IOF (despesas financeiras) da ordem e de cada instrução (common/editCampo no legado). O IOF da instrução é
 * copiado do da ordem na geração e depois independe dele. Cada troca fica em rastreabilidade_alteracao.
 */
class EditPaymentIof
{
    public function __construct(private BookingLog $log) {}

    /** @throws DomainException */
    public function order(FinPaymentOrder|OrdemPagamento $order, float $iof, int $userId): FinPaymentOrder|OrdemPagamento
    {
        $this->save($order, $this->valid($iof), $userId);

        return $order;
    }

    /** @throws DomainException */
    public function instruction(InstrucaoPagamento|InstrucaoPagamentoOutros $instruction, float $iof, int $userId): InstrucaoPagamento|InstrucaoPagamentoOutros
    {
        $this->save($instruction, $this->valid($iof), $userId);

        return $instruction;
    }

    private function save(Model $record, float $iof, int $userId): void
    {
        $old = $record->valor_iof;
        $record->forceFill(['valor_iof' => $iof])->save();
        $format = fn (float $value) => number_format($value, 2, '.', '');

        $this->log->fieldChange($record->getTable(), 'valor_iof', (int) $record->getKey(), $old === null ? null : $format((float) $old), $format($iof), $userId);
    }

    private function valid(float $iof): float
    {
        return $iof >= 0 ? $iof : throw new DomainException('O IOF não pode ser negativo.');
    }
}
