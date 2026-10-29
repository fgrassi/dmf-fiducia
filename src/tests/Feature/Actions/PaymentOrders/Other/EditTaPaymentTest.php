<?php

namespace Tests\Feature\Actions\PaymentOrders\Other;

use App\Actions\PaymentOrders\Other\EditTaPayment;
use App\Models\OrdemPagamento;
use App\Models\TaBaixa;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class EditTaPaymentTest extends TestCase
{
    use LegacySchema;

    private int $expenseId;

    private int $itemId;

    private OrdemPagamento $order;

    /** TA de 10 USD por unidade e 10 unidades: total de 100 USD. */
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'BKG']);
        DB::table('mercadoria')->insert(['embarque_id' => 1, 'quantidade' => 10]);
        $this->expenseId = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 1, 'ref_expense' => 1916, 'buying_rate' => 10, 'ref_currency_br' => 2]);
        $this->order = OrdemPagamento::find(DB::table('ordem_pagamento')->insertGetId(['ref_embarque' => 1, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1]));
        $this->itemId = DB::table('ordem_pagamento_item')->insertGetId([
            'ordem_pagamento_id' => $this->order->getKey(), 'ref_expense' => $this->expenseId, 'unitary_value' => 10, 'ref_currency' => 2,
            'current_rate_value' => 5, 'item_value' => 0, 'created_at' => '2026-10-01', 'status' => 0, 'indice' => 1,
        ]);
    }

    public function test_update_recalculates_the_ta_value_and_syncs_the_item_and_the_order(): void
    {
        $payment = $this->payment(40, 5, 200);
        $this->payment(10, 5, 50);

        app(EditTaPayment::class)->update($this->order, $payment, 4, 200, '2026-10-20', 7);

        $payment->refresh();
        $this->assertEquals(50, $payment->valor_ta);
        $this->assertSame(7, (int) $payment->updated_by);
        $this->assertSame('2026-10-20', $payment->data_pagamento->toDateString());
        $item = DB::table('ordem_pagamento_item')->where('ordem_pagamento_item_id', $this->itemId)->first();
        $this->assertEquals(250, $item->item_value);
        $this->assertEquals(4, $item->current_rate_value);
        $this->assertSame(1, (int) $item->status);
        $order = DB::table('ordem_pagamento')->first();
        $this->assertEquals(250, $order->total_value);
        $this->assertSame(0, (int) $order->status);
    }

    public function test_the_order_is_paid_when_the_balance_reaches_zero_and_the_value_is_capped_at_the_balance(): void
    {
        $this->payment(50, 5, 250);
        $second = $this->payment(10, 5, 50);

        app(EditTaPayment::class)->update($this->order, $second, 5, 250.05, '2026-10-20', 7);

        $this->assertEquals(50, $second->refresh()->valor_ta);
        $this->assertSame(1, (int) DB::table('ordem_pagamento')->value('status'));
    }

    public function test_refuses_a_payment_that_exceeds_the_balance_or_has_invalid_values(): void
    {
        $this->payment(90, 5, 450);
        $second = $this->payment(10, 5, 50);
        $action = app(EditTaPayment::class);

        $this->assertRefuses('O valor pago excede o saldo disponível.', fn () => $action->update($this->order, $second, 5, 500, '2026-10-20', 7));
        $this->assertRefuses('Informe uma taxa válida.', fn () => $action->update($this->order, $second, 0, 50, '2026-10-20', 7));
        $this->assertRefuses('Informe um valor pago maior que zero.', fn () => $action->update($this->order, $second, 5, 0, '2026-10-20', 7));
    }

    public function test_delete_zeroes_the_item_when_no_payment_is_left(): void
    {
        $payment = $this->payment(50, 5, 250);
        app(EditTaPayment::class)->update($this->order, $payment, 5, 250, '2026-10-20', 7);
        $this->assertEquals(250, DB::table('ordem_pagamento')->value('total_value'));

        app(EditTaPayment::class)->delete($this->order, $payment);

        $this->assertModelMissing($payment);
        $item = DB::table('ordem_pagamento_item')->where('ordem_pagamento_item_id', $this->itemId)->first();
        $this->assertEquals(0, $item->item_value);
        $this->assertSame(0, (int) $item->status);
        $this->assertEquals(0, DB::table('ordem_pagamento')->value('total_value'));
        $this->assertSame(0, (int) DB::table('ordem_pagamento')->value('status'));
    }

    public function test_refuses_payments_linked_to_a_capa_from_other_orders_or_on_orders_without_ta(): void
    {
        $linked = $this->payment(10, 5, 50, ['capa_processo_despesa_item_id' => 3]);
        $other = $this->payment(10, 5, 50, ['ordem_pagamento_id' => 999]);
        $action = app(EditTaPayment::class);

        $this->assertRefuses('Baixa já vinculada à capa. Não é possível alterar.', fn () => $action->update($this->order, $linked, 5, 50, '2026-10-20', 7));
        $this->assertRefuses('Baixa já vinculada à capa. Não é possível excluir.', fn () => $action->delete($this->order, $linked));
        $this->assertRefuses('Baixa não pertence a esta ordem de pagamento.', fn () => $action->delete($this->order, $other));

        DB::table('fin_expenses_values')->where('id', $this->expenseId)->update(['ref_expense' => 10]);
        $this->assertFalse($action->orderHasTa($this->order));
        $this->assertRefuses('Esta ordem de pagamento não é de TA.', fn () => $action->delete($this->order, $this->payment(10, 5, 50)));
    }

    private function assertRefuses(string $message, callable $call): void
    {
        try {
            $call();
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }

        $this->fail("Esperava recusa: {$message}");
    }

    /** @param  array<string, mixed>  $attributes */
    private function payment(float $taValue, float $rate, float $paid, array $attributes = []): TaBaixa
    {
        return TaBaixa::find(DB::table('ta_baixa')->insertGetId([
            ...['fin_expenses_values_id' => $this->expenseId, 'embarque_id' => 1, 'ordem_pagamento_id' => $this->order->getKey(), 'ordem_pagamento_item_id' => $this->itemId,
                'valor_ta' => $taValue, 'taxa' => $rate, 'valor_pago' => $paid, 'data_pagamento' => '2026-10-10'],
            ...$attributes,
        ]));
    }
}
