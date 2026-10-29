<?php

namespace App\Actions\PaymentOrders\Other;

use App\Actions\PaymentOrders\PaymentInstruction;
use App\Models\InstrucaoPagamentoOutros;
use App\Models\Porto;
use App\Services\Cobranca\ChargeDetails;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Dados do PDF da instrução de pagamento de Outros (pdfInstrucaoPagamento do legado), no formato de PaymentInstruction. */
class OtherPaymentInstruction
{
    public function __construct(private ChargeDetails $details) {}

    /** @return array<string, mixed> */
    public function __invoke(InstrucaoPagamentoOutros $instruction): array
    {
        $booking = $instruction->order->embarque;
        $import = $booking->impor_expor === 'i';
        $client = DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->first(['nome', 'cnpj']);
        $name = fn (string $table, string $key, $id) => $id ? (string) DB::table($table)->where($key, $id)->value('nome') : '';
        $date = fn (?string $value) => $value ? date('d/m/Y', strtotime($value)) : '';
        $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');

        $items = DB::table('instrucao_pagamento_item_outros as ipi')
            ->join('ordem_pagamento_item as i', 'i.ordem_pagamento_item_id', '=', 'ipi.ordem_pagamento_item_id')
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->leftJoin('cmn_currencies as c', 'c.id', '=', 'v.ref_currency_br')
            ->where('ipi.instrucao_pagamento_outros_id', $instruction->getKey())
            ->orderBy('ipi.instrucao_pagamento_item_outros_id')
            ->get(['ipi.valor', 'i.unitary_value', 'i.current_rate_value', 'c.symbol', 'v.ref_equipment', 'v.ref_bl as v_bl', 'v.ref_container', 'e.name as expense_name', 'u.acronym as unity_name']);

        $subtotal = (float) $items->sum('valor');
        $iof = (float) $instruction->valor_iof;

        return [
            'title' => 'ORDEM PAGAMENTO EXTRA',
            'code' => $instruction->code(),
            'date' => $instruction->created_at->format('d/m/Y'),
            'booking' => (string) $booking->booking,
            'client' => $client ? "{$client->nome} ({$client->cnpj})" : '',
            'mbl' => (string) $booking->master_bl,
            'hbl' => (string) $booking->house_bl,
            'free_time_label' => $import ? 'Free Time Demurrage (dias)' : 'Free Time Detention (dias)',
            'free_time' => (string) ($import ? $booking->free_time : $booking->free_time_detention),
            'ship' => $name('navio', 'navio_id', $import ? $booking->navio_id_destino : $booking->navio_id),
            'origin' => Porto::find($booking->porto_origem_id)?->label ?? '',
            'destination' => Porto::find($booking->porto_destino_id)?->label ?? '',
            'etd' => $date($booking->saida_prevista),
            'eta' => $date($booking->chegada_prevista),
            'equipment' => DB::table('mercadoria as m')->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
                ->where('m.embarque_id', $booking->getKey())->orderBy('m.mercadoria_id')->get(['t.nome', 'm.quantidade'])
                ->map(fn (stdClass $m) => ['name' => (string) $m->nome, 'quantity' => (string) $m->quantidade])->all(),
            'items' => $items->map(fn (stdClass $i) => [
                'expense' => $this->details->expenseLabel($i),
                'buying' => trim("{$i->symbol} ".number_format((float) $i->unitary_value, 2, ',', '.')),
                'rate' => number_format((float) $i->current_rate_value, 8, ',', '.'),
                'value' => $money($i->valor),
            ])->all(),
            'subtotal' => $money($subtotal),
            'iof' => $money($iof),
            'total' => $money($subtotal + $iof),
            'bank_text' => (string) $instruction->dados_bancarios,
        ];
    }
}
