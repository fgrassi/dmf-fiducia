<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\ListPendingCoverValues;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ListPendingCoverValuesTest extends TestCase
{
    use LegacySchema;

    public function test_lists_charge_revenues_not_yet_in_a_cover_and_counts_per_unit_items_by_difference(): void
    {
        $this->booking(1);
        $this->expense(10, 'THC');
        $this->expense(11, 'Frete');
        $this->expense(12, 'Taxa', unity: 5);
        $this->charge(1, 1, [[10, 300], [10, 200], [11, 50], [12, 20], [12, 20], [12, 20]]);
        $cover = DB::table('capa_processo')->insertGetId(['embarque_id' => 1, 'moeda_id' => 1]);
        DB::table('capa_processo_receita_item')->insert([
            ['capa_processo_id' => $cover, 'fin_expenses_values_id' => 11, 'nome' => 'Frete', 'valor' => 50],
            ['capa_processo_id' => $cover, 'fin_expenses_values_id' => 12, 'nome' => 'Taxa', 'valor' => 20],
        ]);

        $pending = $this->pending(1);

        $this->assertSame([['id' => 10, 'name' => 'THC/UN', 'value' => 500.0], ['id' => 12, 'name' => 'Taxa/UN', 'value' => 20.0], ['id' => 12, 'name' => 'Taxa/UN', 'value' => 20.0]], $pending['revenues']);
    }

    public function test_includes_other_bookings_with_the_same_mbl_and_only_unflagged_positive_extras(): void
    {
        $this->booking(1, master: 'MBL1', booking: 'A');
        $this->booking(2, master: 'MBL1', booking: 'B');
        $this->booking(3, master: 'OUTRO', booking: 'C');
        $this->charge(1, 1, [], ['valor_iof' => 10, 'discount' => 5, 'interest' => 0, 'ctr_desconto' => 1]);
        $this->charge(2, 2, [], ['valor_iof' => 7, 'fine' => 3, 'tax_boleto' => 2]);
        $this->charge(3, 3, [], ['valor_iof' => 99]);
        DB::table('fin_payment_orders')->insert([
            ['id' => 1, 'ref_armador' => 1, 'created_at' => '2026-10-01', 'ref_type' => 1, 'ref_embarque' => 2, 'valor_iof' => 4],
            ['id' => 2, 'ref_armador' => 1, 'created_at' => '2026-10-01', 'ref_type' => 1, 'ref_embarque' => 3, 'valor_iof' => 50],
        ]);

        $pending = $this->pending(1);

        $this->assertSame([['id' => 1, 'value' => 10.0], ['id' => 2, 'value' => 7.0]], $pending['iof']);
        $this->assertSame([], $pending['discount']);
        $this->assertSame([], $pending['interest']);
        $this->assertSame([['id' => 2, 'value' => 3.0]], $pending['fine']);
        $this->assertSame([['id' => 2, 'value' => 2.0]], $pending['bank_fee']);
        $this->assertSame([4.0], $pending['purchase_iof']);
    }

    public function test_lists_payment_expenses_skipping_disabled_items_plus_ta_payments_and_marks_those_already_in_cover(): void
    {
        $this->booking(1);
        $this->expense(10, 'Frete');
        $this->expense(11, 'Seguro');
        $this->expense(12, 'Armazenagem');
        $this->expense(13, 'TA', refExpense: 1916);
        DB::table('fin_payment_orders')->insert(['id' => 1, 'ref_armador' => 1, 'created_at' => '2026-10-01', 'ref_type' => 1, 'ref_embarque' => 1, 'payment_date' => '2026-10-05']);
        DB::table('fin_payment_order_items')->insert([
            ['ref_payment_order' => 1, 'ref_expense' => 10, 'ref_currency' => 1, 'item_value' => 100, 'created_at' => '2026-10-01', 'ref_bl' => 1],
            ['ref_payment_order' => 1, 'ref_expense' => 10, 'ref_currency' => 1, 'item_value' => 25, 'created_at' => '2026-10-01', 'ref_bl' => 1],
            ['ref_payment_order' => 1, 'ref_expense' => 11, 'ref_currency' => 1, 'item_value' => 80, 'created_at' => '2026-10-01', 'ref_bl' => 1],
        ]);
        DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => 1, 'ref_expense' => 11, 'created_by' => 1, 'created_at' => '2026-10-01', 'status' => 0]);
        DB::table('ordem_pagamento')->insert(['ordem_pagamento_id' => 1, 'ref_embarque' => 1, 'created_at' => '2026-10-01', 'ref_type' => 1]);
        DB::table('ordem_pagamento_item')->insert([
            ['ordem_pagamento_id' => 1, 'ref_expense' => 12, 'ref_currency' => 1, 'item_value' => 60, 'created_at' => '2026-10-01', 'status' => 1],
            ['ordem_pagamento_id' => 1, 'ref_expense' => 11, 'ref_currency' => 1, 'item_value' => 90, 'created_at' => '2026-10-01', 'status' => 0],
        ]);
        $payment = ['fin_expenses_values_id' => 13, 'embarque_id' => 1, 'valor_ta' => 1, 'taxa' => 1];
        DB::table('ta_baixa')->insert($payment + ['valor_pago' => 30, 'data_pagamento' => '2026-10-02', 'capa_processo_despesa_item_id' => null]);
        DB::table('ta_baixa')->insert($payment + ['valor_pago' => 40, 'data_pagamento' => '2026-10-03', 'capa_processo_despesa_item_id' => 9]);
        $cover = DB::table('capa_processo')->insertGetId(['embarque_id' => 1, 'moeda_id' => 1]);
        DB::table('capa_processo_despesa_item')->insert(['capa_processo_id' => $cover, 'fin_expenses_values_id' => 12, 'nome' => 'Armazenagem', 'valor' => 60]);

        $pending = $this->pending(1);

        $this->assertSame(
            [['id' => 10, 'name' => 'Frete/UN', 'value' => 125.0], ['id' => 13, 'name' => 'TA/UN', 'value' => 30.0, 'ta_payment_id' => 1]],
            $pending['expenses'],
        );
        $this->assertSame([['id' => 12, 'name' => 'Armazenagem/UN', 'value' => 60.0]], $pending['covered_expenses']);
    }

    /** @return array<string, mixed> */
    private function pending(int $bookingId): array
    {
        return app(ListPendingCoverValues::class)(Embarque::findOrFail($bookingId));
    }

    private function booking(int $id, ?string $master = null, ?string $booking = null): void
    {
        DB::table('embarque')->insert(['embarque_id' => $id, 'master_bl' => $master, 'booking' => $booking ?? "B{$id}"]);
    }

    private function expense(int $valueId, string $name, int $unity = 1, int $refExpense = 0): void
    {
        DB::table('fin_expenses_unities')->insertOrIgnore(['id' => $unity, 'name' => 'Unidade', 'acronym' => 'UN']);
        $refExpense = $refExpense ?: $valueId;
        DB::table('fin_expenses')->insert(['id' => $refExpense, 'name' => $name, 'ref_unity' => $unity]);
        DB::table('fin_expenses_values')->insert(['id' => $valueId, 'ref_expense' => $refExpense, 'ref_embarque' => 1]);
    }

    /**
     * @param  list<array{0: int, 1: float}>  $items
     * @param  array<string, mixed>  $extras
     */
    private function charge(int $id, int $bookingId, array $items, array $extras = []): void
    {
        DB::table('fin_charges')->insert(['id' => $id, 'ref_type' => 1, 'ref_booking' => $bookingId, 'created_at' => '2026-10-01'] + $extras);
        DB::table('fin_charges_items')->insert(array_map(fn ($i) => ['ref_charge' => $id, 'ref_expense' => $i[0], 'item_value' => $i[1], 'ref_currency' => 1, 'created_at' => '2026-10-01'], $items));
    }
}
