<?php

namespace Tests\Feature\Actions\PaymentOrders\Other;

use App\Actions\PaymentOrders\Other\DeleteOtherPaymentOrder;
use App\Actions\PaymentOrders\Other\EditOtherOrderItem;
use App\Actions\PaymentOrders\Other\OtherOrderItemValue;
use App\Actions\PaymentOrders\Other\OtherOrderParties;
use App\Actions\PaymentOrders\Other\RecalculateOtherPaymentOrder;
use App\Models\Embarque;
use App\Models\OrdemPagamento;
use App\Models\OrdemPagamentoItem;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class OtherOrderItemsTest extends TestCase
{
    use LegacySchema;

    private const USD = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-29 10:00');
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']]);
        DB::table('fin_expenses_unities')->insert([['id' => 1, 'name' => 'BL', 'acronym' => 'BL'], ['id' => 2, 'name' => 'CNT', 'acronym' => 'CNT'], ['id' => 3, 'name' => 'W/M', 'acronym' => 'W/M'], ['id' => 5, 'name' => 'Proc', 'acronym' => 'PR']]);
        foreach ([[10, 'Despacho', 1, null, null], [11, 'Seguro', 5, null, null], [12, 'Frete aéreo', 3, 100, 1], [13, 'THC', 2, null, null], [1482, 'Desconto', 1, null, null]] as [$id, $name, $unity, $min, $currency]) {
            DB::table('fin_expenses')->insert(['id' => $id, 'name' => $name, 'ref_unity' => $unity, 'min_value' => $min, 'ref_currency' => $currency]);
        }
        DB::table('fin_expenses_values_due_to')->insert([['id' => 5, 'name' => 'Despachante'], ['id' => 8, 'name' => 'Seguradora']]);
    }

    public function test_debit_follows_the_unit_of_the_expense_and_the_discount_is_always_negative(): void
    {
        $booking = $this->booking(['qtd_bl' => 3]);
        DB::table('bl')->insert([['embarque_id' => $booking->getKey(), 'bl' => 'H1'], ['embarque_id' => $booking->getKey(), 'bl' => 'H2'], ['embarque_id' => $booking->getKey(), 'bl' => '']]);
        DB::table('mercadoria')->insert([['embarque_id' => $booking->getKey(), 'tipo_container_id' => 7, 'quantidade' => 2], ['embarque_id' => $booking->getKey(), 'tipo_container_id' => 8, 'quantidade' => 3]]);
        $value = app(OtherOrderItemValue::class);

        $this->assertSame(1650.0, $value->debit($this->expense(10), 5.5, 100, $booking));
        $this->assertSame(275.0, $value->debit($this->expense(11), 5.5, 100, $booking));
        $this->assertSame(2750.0, $value->debit($this->expense(13), 5.5, 100, $booking));
        $this->assertSame(1100.0, $value->debit($this->expense(13, ['ref_equipment' => 7]), 5.5, 100, $booking));
        $this->assertSame(-80.0, $value->debit($this->expense(1482), 1, 80, $booking));
        $this->assertSame(-80.0, $value->debit($this->expense(1482), 1, -80, $booking));
    }

    public function test_weight_measure_respects_the_expense_minimum_value(): void
    {
        $booking = $this->booking();
        $value = app(OtherOrderItemValue::class);

        $this->assertSame(100.0, $value->debit($this->expense(12), 1, 10, $booking));
        $this->assertSame(250.0, $value->debit($this->expense(12), 1, 250, $booking));
    }

    public function test_index_and_unit_value_with_commissions(): void
    {
        $booking = $this->booking(['qtd_bl' => 4]);
        DB::table('fin_expenses')->insert([['id' => 33, 'name' => 'Arbitrary', 'ref_unity' => 1]]);
        $freight = $this->expense(10, ['buying_rate' => 100]);
        $this->expense(33, ['buying_rate' => 25]);
        $value = app(OtherOrderItemValue::class);

        $this->assertSame([4.0, 1.0], [$value->index($freight, $booking), $value->index($this->expense(11), $booking)]);
        $this->assertSame(100.0, $value->unitWithCommissions($booking, $freight));

        $one = $this->expense(1, ['buying_rate' => 300]);
        DB::table('fin_expenses')->insertOrIgnore(['id' => 1, 'name' => 'Ocean Freight', 'ref_unity' => 2]);

        $this->assertSame(325.0, $value->unitWithCommissions($booking, $one));
    }

    public function test_editing_the_item_follows_the_legacy_formulas_and_toggles_the_status(): void
    {
        $order = $this->order();
        $foreign = $this->item($order, 10, unit: 100, rate: 5, index: 2, value: 1000);
        $real = $this->item($order, 11, unit: 100, rate: 1, index: 2, value: 200, currency: 1);
        $edit = app(EditOtherOrderItem::class);

        $edit->unitValue($foreign, 120);
        $this->assertSame(1200.0, $foreign->fresh()->item_value);

        $edit->unitValue($real, 150);
        $this->assertSame(300.0, $real->fresh()->item_value);

        $edit->rate($foreign->fresh(), 6);
        $this->assertSame([6.0, 1440.0], [$foreign->fresh()->current_rate_value, $foreign->fresh()->item_value]);
        $this->assertSame(1740.0, (float) $order->fresh()->total_value);

        $edit->index($foreign->fresh(), 3);
        $this->assertSame(2160.0, $foreign->fresh()->item_value);

        $edit->index($real->fresh(), 2);
        $this->assertSame(600.0, $real->fresh()->item_value);
        $edit->index($real->fresh(), 2);
        $this->assertSame(1200.0, $real->fresh()->item_value);

        $this->assertFalse($edit->toggle($foreign->fresh()));
        $this->assertNull($foreign->fresh()->status);
        $this->assertTrue($edit->toggle($foreign->fresh()));
        $this->assertSame(1, (int) $foreign->fresh()->status);
    }

    public function test_recalculating_with_the_typed_rates_updates_items_total_and_creates_one_instruction_per_party(): void
    {
        $booking = $this->booking(['qtd_bl' => 1]);
        DB::table('despachante')->insert(['despachante_id' => 3, 'nome' => 'Despachante Sul', 'cnpj' => null, 'dados_bancarios' => null]);
        $order = $this->order(['valor_iof' => 4]);
        $dispatch = $this->item($order, 10, unit: 100, rate: 1, index: 2, value: 0, status: 1, dueTo: 5);
        $insurance = $this->item($order, 11, unit: 50, rate: 1, index: 1, value: 0, status: 1, dueTo: 8);
        $off = $this->item($order, 11, unit: 30, rate: 1, index: 1, value: 0, status: null, dueTo: 8);

        $result = app(RecalculateOtherPaymentOrder::class)($order, [self::USD => 5.5], false, [
            5 => ['id' => 3, 'class' => 'Despachante', 'name' => 'Despachante Sul', 'cnpj' => '11.222', 'bank' => 'Banco X ag 1'],
            8 => ['id' => null, 'class' => 'CiaSeguro', 'name' => 'Seguradora Y', 'cnpj' => '33', 'bank' => 'Banco Y'],
        ], 7);

        $this->assertSame(['status' => 'OK', 'message' => 'Instrução de pagamento atualizada com sucesso'], $result);
        $this->assertSame([1100.0, 275.0, 165.0], [$dispatch->fresh()->item_value, $insurance->fresh()->item_value, $off->fresh()->item_value]);
        $this->assertSame([1540.0, 7, '2026-10-29'], [(float) $order->fresh()->total_value, (int) $order->fresh()->updated_by, $order->fresh()->updated_at]);
        $this->assertSame(['11.222', 'Banco X ag 1'], [DB::table('despachante')->value('cnpj'), DB::table('despachante')->value('dados_bancarios')]);
        $this->assertSame(
            [['Despachante Sul', '11.222', 4.0], ['Seguradora Y', '33', 4.0]],
            DB::table('instrucao_pagamento_outros')->orderBy('instrucao_pagamento_outros_id')->get()->map(fn ($i) => [$i->recebedor, $i->cnpj_recebedor, (float) $i->valor_iof])->all(),
        );
        $this->assertSame([1100.0, 275.0], DB::table('instrucao_pagamento_item_outros')->orderBy('instrucao_pagamento_item_outros_id')->pluck('valor')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(1, DB::table('instrucao_pagamento_parte')->count());

        app(RecalculateOtherPaymentOrder::class)($order->fresh(), [self::USD => 6], false, [5 => ['id' => 3, 'class' => 'Despachante', 'name' => 'Despachante Sul', 'cnpj' => '11.222', 'bank' => 'Banco X ag 1'], 8 => ['name' => 'Seguradora Y', 'bank' => 'Banco Y']], 7);

        $this->assertSame([2, 2], [DB::table('instrucao_pagamento_outros')->count(), DB::table('instrucao_pagamento_item_outros')->count()]);
        $this->assertSame(300.0, (float) DB::table('instrucao_pagamento_item_outros')->where('ordem_pagamento_item_id', $insurance->getKey())->value('valor'));
    }

    public function test_recalculating_with_reset_rebuilds_values_from_the_booking_expenses(): void
    {
        $booking = $this->booking(['qtd_bl' => 3]);
        $order = $this->order();
        $expenseId = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $booking->getKey(), 'ref_expense' => 10, 'ref_due_to' => 5, 'buying_rate' => 40, 'ref_currency_br' => self::USD]);
        $item = OrdemPagamentoItem::find(DB::table('ordem_pagamento_item')->insertGetId([
            'ordem_pagamento_id' => $order->getKey(), 'ref_expense' => $expenseId, 'unitary_value' => 1, 'ref_currency' => 1, 'current_rate_value' => 1, 'item_value' => 1, 'created_at' => '2026-10-01', 'status' => 1, 'indice' => 1,
        ]));

        app(RecalculateOtherPaymentOrder::class)($order, [self::USD => 5], true, [5 => ['name' => 'X', 'bank' => 'B']], 7);

        $this->assertSame([40.0, self::USD, 5.0, 600.0, 3.0], [$item->fresh()->unitary_value, (int) $item->fresh()->ref_currency, $item->fresh()->current_rate_value, $item->fresh()->item_value, (float) $item->fresh()->indice]);
    }

    public function test_recalculation_warns_without_enabled_items_and_refuses_a_closed_order(): void
    {
        $this->booking();
        $order = $this->order();
        $this->item($order, 10, unit: 1, rate: 1, index: 1, value: 1, status: null, dueTo: 5);

        $this->assertSame('WARNING', app(RecalculateOtherPaymentOrder::class)($order, [], false, [], 7)['status']);

        $closed = $this->order(['status' => 1]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A ordem de pagamento já foi paga ou cancelada.');

        app(RecalculateOtherPaymentOrder::class)($closed, [], false, [], 7);
    }

    public function test_parties_come_from_the_instruction_or_from_the_booking_and_can_be_searched(): void
    {
        $this->booking(['ref_cia_aerea' => 9, 'despachante_id' => 3]);
        DB::table('cia_aerea')->insert(['id' => 9, 'name' => 'LATAM', 'cnpj' => '99', 'dados_bancarios' => 'Banco L']);
        DB::table('despachante')->insert(['despachante_id' => 3, 'nome' => 'Despachante Sul', 'cnpj' => '33', 'dados_bancarios' => 'Banco D']);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 2, 'name' => 'Cia Aérea']);
        $order = $this->order();
        $this->item($order, 10, unit: 1, rate: 1, index: 1, value: 1, status: 1, dueTo: 2);
        $this->item($order, 11, unit: 1, rate: 1, index: 1, value: 1, status: 1, dueTo: 5);
        $parties = app(OtherOrderParties::class);

        $found = $parties->forOrder($order);

        $this->assertSame(['CiaAerea', 9, 'LATAM', '99', 'Banco L'], [$found[2]['class'], $found[2]['id'], $found[2]['name'], $found[2]['cnpj'], $found[2]['bank']]);
        $this->assertSame(['Despachante', 3, 'Despachante Sul'], [$found[5]['class'], $found[5]['id'], $found[5]['name']]);
        $this->assertSame([3 => 'Despachante Sul'], $parties->search('Despachante', 'Sul'));
        $this->assertNull($parties->lookup('Despachante', 999));
    }

    public function test_deleting_the_order_removes_items_and_instructions_and_reopens_the_booking(): void
    {
        $booking = $this->booking(['ctr_pgto_outros' => 0]);
        $order = $this->order();
        $other = $this->order();
        $item = $this->item($order, 10, unit: 1, rate: 1, index: 1, value: 1, status: 1, dueTo: 5);
        $this->item($other, 10, unit: 1, rate: 1, index: 1, value: 1, status: 1, dueTo: 5);
        $instruction = DB::table('instrucao_pagamento_outros')->insertGetId(['ordem_pagamento_id' => $order->getKey(), 'created_at' => '2026-10-01']);
        DB::table('instrucao_pagamento_item_outros')->insert(['instrucao_pagamento_outros_id' => $instruction, 'ordem_pagamento_item_id' => $item->getKey(), 'valor' => 1]);

        app(DeleteOtherPaymentOrder::class)($order);

        $this->assertModelMissing($order);
        $this->assertModelExists($other);
        $this->assertSame([0, 0, 1, 1], [DB::table('instrucao_pagamento_outros')->count(), DB::table('instrucao_pagamento_item_outros')->count(), OrdemPagamentoItem::count(), (int) $booking->fresh()->ctr_pgto_outros]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'BKG', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'qtd_bl' => 1], ...$attributes]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function order(array $attributes = []): OrdemPagamento
    {
        return OrdemPagamento::find(DB::table('ordem_pagamento')->insertGetId([...['ref_embarque' => 1, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1], ...$attributes]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(int $type, array $attributes = []): object
    {
        $id = DB::table('fin_expenses_values')->insertGetId([...['ref_embarque' => 1, 'ref_expense' => $type, 'buying_rate' => 10, 'ref_currency_br' => self::USD], ...$attributes]);

        return DB::table('fin_expenses_values')->where('id', $id)->first();
    }

    private function item(OrdemPagamento $order, int $type, float $unit, float $rate, float $index, float $value, ?int $status = 1, int $currency = self::USD, int $dueTo = 5): OrdemPagamentoItem
    {
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $order->ref_embarque, 'ref_expense' => $type, 'ref_due_to' => $dueTo, 'buying_rate' => $unit, 'ref_currency_br' => $currency]);

        return OrdemPagamentoItem::find(DB::table('ordem_pagamento_item')->insertGetId([
            'ordem_pagamento_id' => $order->getKey(), 'ref_expense' => $expense, 'unitary_value' => $unit, 'ref_currency' => $currency,
            'current_rate_value' => $rate, 'item_value' => $value, 'created_at' => '2026-10-01', 'status' => $status, 'indice' => $index,
        ]));
    }
}
