<?php

namespace Tests\Feature\Actions\PaymentOrders;

use App\Actions\PaymentOrders\DeleteOrderCustomRate;
use App\Actions\PaymentOrders\DeletePaymentOrder;
use App\Actions\PaymentOrders\EditOrderItem;
use App\Actions\PaymentOrders\OrderItemValue;
use App\Actions\PaymentOrders\RecalculatePaymentOrder;
use App\Actions\PaymentOrders\SaveOrderCustomRate;
use App\Actions\PaymentOrders\ToggleOrderExpense;
use App\Models\FinCustomPaymentOrderCurrencyRate;
use App\Models\FinPaymentOrder;
use App\Models\FinPaymentOrderItem;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PaymentOrderItemsTest extends TestCase
{
    use LegacySchema;

    private const USD = 2;

    private const BL_UNIT = 1;

    private const CONTAINER_UNIT = 2;

    private const WEIGHT_MEASURE_UNIT = 3;

    public function test_debit_for_bl_unit_is_unit_times_rate_rounded(): void
    {
        $expense = $this->expense(unity: self::BL_UNIT);

        $this->assertSame(551.23, app(OrderItemValue::class)->debit($expense, 5.5123, 100, 'MBL1'));
    }

    public function test_debit_for_container_unit_multiplies_by_the_bl_container_factor(): void
    {
        $booking = DB::table('embarque')->insertGetId(['booking' => 'B', 'impor_expor' => 'e']);
        $bl = DB::table('bl')->insertGetId(['embarque_id' => $booking, 'mbl_number' => 'MBL1']);
        DB::table('container')->insert(['bl_id' => $bl, 'numero' => 'C1', 'volume' => '10']);
        $expense = $this->expense(unity: self::CONTAINER_UNIT, booking: $booking);

        $this->assertSame(500.0, app(OrderItemValue::class)->debit($expense, 5, 100, 'MBL1'));
    }

    public function test_debit_for_weight_measure_uses_the_mbl_total_split_by_the_booking_bls_and_the_minimum(): void
    {
        $booking = DB::table('embarque')->insertGetId(['booking' => 'B', 'impor_expor' => 'e']);
        DB::table('bl')->insert([
            ['embarque_id' => $booking, 'mbl_number' => 'MBL1', 'gross_weight' => '4.000', 'volume' => '1'],
            ['embarque_id' => $booking, 'mbl_number' => 'MBL1', 'gross_weight' => '2.000', 'volume' => '1'],
        ]);
        $expense = $this->expense(unity: self::WEIGHT_MEASURE_UNIT, booking: $booking, extra: ['br_min' => 0]);

        $this->assertSame(60.0, app(OrderItemValue::class)->debit($expense, 1, 10, 'MBL1'));

        $withMinimum = $this->expense(unity: self::WEIGHT_MEASURE_UNIT, booking: $booking, extra: ['br_min' => 500, 'ref_currency_br' => 1]);

        $this->assertSame(500.0, app(OrderItemValue::class)->debit($withMinimum, 1, 10, 'MBL1'));
    }

    public function test_debit_is_zero_when_the_expense_is_disabled_in_an_order_of_the_same_mbl(): void
    {
        $expense = $this->expense(unity: self::BL_UNIT);
        $order = $this->order(['mbl' => 'MBL1']);
        DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $order->getKey(), 'ref_expense' => $expense->id, 'created_by' => 1, 'created_at' => '2026-10-01', 'status' => 0]);

        $this->assertSame(0.0, app(OrderItemValue::class)->debit($expense, 5, 100, 'MBL1'));
        $this->assertSame(500.0, app(OrderItemValue::class)->debit($expense, 5, 100, 'OUTRO'));
    }

    public function test_quote_prefers_the_expense_negotiation_then_the_currency_one_then_todays_rate(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $order = $this->order();
        $expense = $this->expense(unity: self::BL_UNIT);
        $rate = DB::table('fin_currencies_rates_armador_payment')->insertGetId(['ref_armador' => 7, 'ref_currency' => self::USD, 'type' => 'e', 'name' => 'x']);
        DB::table('fin_currencies_rates_armador_payment_values')->insert(['ref_currency_rate_armador' => $rate, 'rate' => 5.1234567, 'rate_date' => '2026-10-29', 'created_at' => '2026-10-29']);
        $quote = fn () => app(OrderItemValue::class)->quote(self::USD, 7, 'e', $order->getKey(), $expense->id);

        $this->assertSame(1.0, app(OrderItemValue::class)->quote(1, 7, 'e', $order->getKey(), $expense->id));
        $this->assertSame(5.1235, $quote());

        DB::table('fin_custom_payment_order_currency_rate')->insert(['ref_payment_order' => $order->getKey(), 'ref_currency' => self::USD, 'ref_expense' => null, 'custom_rate' => 6, 'created_at' => '2026-10-29', 'created_by' => 1]);
        $this->assertSame(6.0, $quote());

        DB::table('fin_custom_payment_order_currency_rate')->insert(['ref_payment_order' => $order->getKey(), 'ref_currency' => self::USD, 'ref_expense' => $expense->id, 'custom_rate' => 7, 'created_at' => '2026-10-29', 'created_by' => 1]);
        $this->assertSame(7.0, $quote());
    }

    public function test_toggle_flips_the_expense_once_even_when_it_has_several_items(): void
    {
        $order = $this->order();
        $expense = $this->expense(unity: self::BL_UNIT);
        $this->item($order, $expense, bl: 1);
        $this->item($order, $expense, bl: 2);

        $this->assertFalse(app(ToggleOrderExpense::class)($order, $expense->id, 7));
        $this->assertSame([0, 7], [(int) DB::table('fin_payment_order_items_disabled')->value('status'), (int) DB::table('fin_payment_order_items_disabled')->value('created_by')]);
        $this->assertTrue(app(ToggleOrderExpense::class)($order, $expense->id, 7));
        $this->assertSame(1, (int) DB::table('fin_payment_order_items_disabled')->value('status'));
        $this->assertFalse(app(ToggleOrderExpense::class)($order, $expense->id, 7));
        $this->assertSame(1, DB::table('fin_payment_order_items_disabled')->count());
    }

    public function test_toggle_rejects_an_expense_that_is_not_in_the_order(): void
    {
        $this->expectException(DomainException::class);

        app(ToggleOrderExpense::class)($this->order(), 999, 7);
    }

    public function test_editing_the_unit_value_or_the_rate_refreshes_the_item_value(): void
    {
        $order = $this->order(['mbl' => 'MBL1']);
        $expense = $this->expense(unity: self::BL_UNIT);
        $item = $this->item($order, $expense, bl: 1, unit: 100, rate: 5, value: 500);

        app(EditOrderItem::class)->unitValue($item, 120);
        $this->assertSame([120.0, 600.0], [$item->fresh()->unitary_value, $item->fresh()->item_value]);

        app(EditOrderItem::class)->rate($item->fresh(), 5.5);
        $this->assertSame([5.5, 660.0], [$item->fresh()->current_rate_value, $item->fresh()->item_value]);
    }

    public function test_recalculates_the_open_order_with_todays_rate_and_skips_items_already_in_an_instruction(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = DB::table('embarque')->insertGetId(['booking' => 'B', 'impor_expor' => 'e', 'armador_id' => 7]);
        $order = $this->order(['mbl' => 'MBL1', 'ref_embarque' => $booking]);
        $expense = $this->expense(unity: self::BL_UNIT, booking: $booking, extra: ['buying_rate' => 100, 'ref_currency_br' => self::USD]);
        $free = $this->item($order, $expense, bl: 1, unit: 1, rate: 1, value: 1);
        $locked = $this->item($order, $expense, bl: 2, unit: 1, rate: 1, value: 1);
        DB::table('instrucao_pagamento_item')->insert(['instrucao_pagamento_id' => 1, 'fin_payment_order_items_id' => $locked->id, 'valor' => 1]);
        $rate = DB::table('fin_currencies_rates_armador_payment')->insertGetId(['ref_armador' => 7, 'ref_currency' => self::USD, 'type' => 'e', 'name' => 'x']);
        DB::table('fin_currencies_rates_armador_payment_values')->insert(['ref_currency_rate_armador' => $rate, 'rate' => 5, 'rate_date' => '2026-10-29', 'created_at' => '2026-10-29']);

        $total = app(RecalculatePaymentOrder::class)($order);

        $this->assertSame([100.0, 5.0, 500.0], [$free->fresh()->unitary_value, $free->fresh()->current_rate_value, $free->fresh()->item_value]);
        $this->assertSame(1.0, $locked->fresh()->item_value);
        $this->assertSame([501.0, 501.0, '2026-10-29'], [$total, $order->fresh()->total_value, $order->fresh()->updated_at]);
    }

    public function test_recalculation_refuses_a_paid_order_and_missing_quotes(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = DB::table('embarque')->insertGetId(['booking' => 'B', 'impor_expor' => 'e', 'armador_id' => 7]);
        DB::table('cmn_currencies')->insert(['id' => self::USD, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('armador')->insert(['armador_id' => 7, 'nome' => 'MAERSK']);
        $paid = $this->order(['status' => 1, 'ref_embarque' => $booking]);
        $open = $this->order(['ref_embarque' => $booking]);
        $expense = $this->expense(unity: self::BL_UNIT, booking: $booking, extra: ['ref_currency_br' => self::USD]);
        $this->item($open, $expense, bl: 1);

        try {
            app(RecalculatePaymentOrder::class)($paid);
            $this->fail('A ordem paga deveria ser recusada.');
        } catch (DomainException $e) {
            $this->assertSame('Não é possível recalcular pagamentos pagas.', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não foi possível atualizar o pagamento. Taxas desatualizadas. Reserva: B. Moeda: Dólar (USD) (Exportação). Armador: MAERSK');

        app(RecalculatePaymentOrder::class)($open);
    }

    public function test_deleting_the_order_removes_its_items_instructions_and_controls_and_reopens_the_booking_payment(): void
    {
        $booking = DB::table('embarque')->insertGetId(['booking' => 'B', 'impor_expor' => 'e', 'ctr_pgto' => 0]);
        $order = $this->order(['ref_embarque' => $booking]);
        $other = $this->order(['ref_embarque' => $booking]);
        $expense = $this->expense(unity: self::BL_UNIT, booking: $booking);
        $item = $this->item($order, $expense, bl: 1);
        $this->item($other, $expense, bl: 1);
        DB::table('instrucao_pagamento')->insert(['fin_payment_order_id' => $order->getKey(), 'created_at' => '2026-10-01']);
        DB::table('instrucao_pagamento_item')->insert(['instrucao_pagamento_id' => 1, 'fin_payment_order_items_id' => $item->id, 'valor' => 1]);
        DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $order->getKey(), 'ref_expense' => $expense->id, 'created_by' => 1, 'created_at' => '2026-10-01', 'status' => 0]);

        app(DeletePaymentOrder::class)($order);

        $this->assertModelMissing($order);
        $this->assertModelExists($other);
        $this->assertSame([0, 0, 0, 1], [DB::table('instrucao_pagamento')->count(), DB::table('instrucao_pagamento_item')->count(), DB::table('fin_payment_order_items_disabled')->count(), FinPaymentOrderItem::count()]);
        $this->assertSame(1, (int) DB::table('embarque')->value('ctr_pgto'));
    }

    public function test_saves_and_deletes_a_negotiated_rate_and_validates_it(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = DB::table('embarque')->insertGetId(['booking' => 'B', 'impor_expor' => 'e']);
        $order = $this->order(['ref_embarque' => $booking]);
        $expense = $this->expense(unity: self::BL_UNIT, booking: $booking);

        $custom = app(SaveOrderCustomRate::class)($order, self::USD, $expense->id, 5.25, 'acordo', 7);

        $this->assertSame([self::USD, $expense->id, 5.25, 'acordo', 7, '2026-10-29'], [(int) $custom->ref_currency, (int) $custom->ref_expense, $custom->custom_rate, $custom->note, (int) $custom->created_by, $custom->created_at->toDateString()]);

        app(DeleteOrderCustomRate::class)($custom);

        $this->assertModelMissing($custom);
        $this->assertSame(0, FinCustomPaymentOrderCurrencyRate::count());
    }

    public function test_rejects_a_zero_negotiated_rate_and_an_expense_of_another_booking(): void
    {
        $order = $this->order();
        $foreign = $this->expense(unity: self::BL_UNIT, booking: 99);

        foreach ([[null, 0.0, 'Informe um valor negociado maior que zero.'], [$foreign->id, 5.0, 'A despesa não pertence à reserva da ordem.']] as [$expenseId, $rate, $message]) {
            try {
                app(SaveOrderCustomRate::class)($order, self::USD, $expenseId, $rate, null, 7);
                $this->fail('A cotação deveria ser recusada.');
            } catch (DomainException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame(0, FinCustomPaymentOrderCurrencyRate::count());
    }

    /** @param  array<string, mixed>  $attributes */
    private function order(array $attributes = []): FinPaymentOrder
    {
        return FinPaymentOrder::find(DB::table('fin_payment_orders')->insertGetId([
            ...['ref_armador' => 7, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1, 'ref_embarque' => 10, 'updated_at' => null],
            ...$attributes,
        ]));
    }

    /** @param  array<string, mixed>  $extra */
    private function expense(int $unity, int $booking = 10, array $extra = []): object
    {
        DB::table('fin_expenses')->insertOrIgnore(['id' => 100 + $unity, 'name' => "Despesa {$unity}", 'ref_unity' => $unity]);
        $id = DB::table('fin_expenses_values')->insertGetId([...['ref_embarque' => $booking, 'ref_expense' => 100 + $unity, 'buying_rate' => 100, 'ref_currency_br' => self::USD, 'ref_due_to' => 3], ...$extra]);

        return DB::table('fin_expenses_values')->where('id', $id)->first();
    }

    private function item(FinPaymentOrder $order, object $expense, int $bl, float $unit = 100, float $rate = 5, float $value = 500): FinPaymentOrderItem
    {
        return FinPaymentOrderItem::find(DB::table('fin_payment_order_items')->insertGetId([
            'ref_payment_order' => $order->getKey(), 'ref_expense' => $expense->id, 'unitary_value' => $unit, 'ref_currency' => self::USD,
            'current_rate_value' => $rate, 'item_value' => $value, 'created_at' => '2026-10-01', 'ref_bl' => $bl,
        ]));
    }
}
