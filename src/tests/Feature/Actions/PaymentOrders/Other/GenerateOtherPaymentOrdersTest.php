<?php

namespace Tests\Feature\Actions\PaymentOrders\Other;

use App\Actions\PaymentOrders\Other\GenerateOtherPaymentOrders;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class GenerateOtherPaymentOrdersTest extends TestCase
{
    use LegacySchema;

    private const DISPATCHER = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-29 10:00');
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']]);
        DB::table('fin_expenses_unities')->insert([['id' => 1, 'name' => 'BL', 'acronym' => 'BL'], ['id' => 2, 'name' => 'CNT', 'acronym' => 'CNT'], ['id' => 5, 'name' => 'Proc', 'acronym' => 'PR']]);
        foreach ([[10, 'Despacho', 1], [11, 'Seguro', 5], [13, 'THC', 2], [1482, 'Desconto', 1]] as [$id, $name, $unity]) {
            DB::table('fin_expenses')->insert(['id' => $id, 'name' => $name, 'ref_unity' => $unity]);
        }
    }

    public function test_automatic_generation_creates_the_order_with_off_items_at_rate_zero_and_takes_the_booking_off_the_queue(): void
    {
        $booking = $this->booking(['qtd_bl' => 3]);
        $despacho = $this->expense($booking, 10, ['buying_rate' => 100]);
        $this->expense($booking, 1482, ['buying_rate' => 80]);
        $this->expense($booking, 11, ['buying_rate' => 50, 'ref_due_to' => 3]);
        $this->expense($booking, 11, ['buying_rate' => 50, 'ref_due_to' => 10]);
        $this->expense($booking, 11, ['buying_rate' => 0]);
        $this->expense($booking, 11, ['buying_rate' => 70, 'billing' => 'p']);

        $this->assertSame(1, app(GenerateOtherPaymentOrders::class)->all());

        $order = DB::table('ordem_pagamento')->sole();
        $this->assertSame([(int) $booking->getKey(), '2026-10-29', 1, 0], [(int) $order->ref_embarque, substr($order->created_at, 0, 10), (int) $order->ref_type, (int) $order->status]);
        $items = DB::table('ordem_pagamento_item')->orderBy('ordem_pagamento_item_id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($despacho, (int) $items[1]->ref_expense);
        $this->assertEquals([100, 0, 3, null, 0], [$items[1]->unitary_value, $items[1]->item_value, $items[1]->indice, $items[1]->status, $items[1]->current_rate_value]);
        $this->assertEquals([-80, 3], [$items[0]->item_value, $items[0]->indice]);
        $this->assertEquals(-80, $order->total_value);
        $this->assertSame(0, (int) DB::table('embarque')->value('ctr_pgto_outros'));
    }

    public function test_a_second_order_for_the_same_booking_is_extra(): void
    {
        $booking = $this->booking();
        $this->expense($booking, 10, ['buying_rate' => 100]);
        app(GenerateOtherPaymentOrders::class)->all();
        $this->expense($booking, 10, ['buying_rate' => 40]);
        DB::table('embarque')->update(['ctr_pgto_outros' => 1]);

        $this->assertSame(1, app(GenerateOtherPaymentOrders::class)->all());

        $this->assertSame([1, 2], DB::table('ordem_pagamento')->orderBy('ordem_pagamento_id')->pluck('ref_type')->map(fn ($t) => (int) $t)->all());
    }

    public function test_only_pending_bookings_of_the_legacy_groups_are_picked_up(): void
    {
        $marine = $this->booking(['booking' => 'MARITIMA', 'arrived' => false]);
        $this->expense($marine, 10, ['buying_rate' => 100]);
        $air = $this->booking(['booking' => 'AEREO', 'tipo_embarque' => 'a']);
        $this->expense($air, 10, ['buying_rate' => 100]);
        $export = $this->booking(['booking' => 'EXPO', 'impor_expor' => 'e']);
        $this->expense($export, 10, ['buying_rate' => 100]);
        $insurance = $this->booking(['booking' => 'SEGURO', 'tipo_embarque' => 's', 'impor_expor' => 'e']);
        $this->expense($insurance, 10, ['buying_rate' => 100]);
        $handling = $this->booking(['booking' => 'HANDLING', 'tipo_embarque' => 'h']);
        $this->expense($handling, 10, ['buying_rate' => 100]);
        $done = $this->booking(['booking' => 'FEITA', 'impor_expor' => 'e', 'ctr_pgto_outros' => 0]);
        $this->expense($done, 10, ['buying_rate' => 100]);

        $this->assertSame(3, app(GenerateOtherPaymentOrders::class)->all());

        $this->assertEqualsCanonicalizing(['AEREO', 'EXPO', 'SEGURO'], Embarque::whereIn('embarque_id', DB::table('ordem_pagamento')->pluck('ref_embarque'))->pluck('booking')->all());
        $this->assertSame(1, (int) $marine->fresh()->ctr_pgto_outros);
    }

    public function test_marine_import_needs_the_arrival_confirmation(): void
    {
        $booking = $this->booking(['arrived' => false]);
        $this->expense($booking, 10, ['buying_rate' => 100]);

        $this->assertSame(0, app(GenerateOtherPaymentOrders::class)->all());
    }

    public function test_invalid_booking_is_skipped_and_stays_pending_but_one_without_pending_expenses_leaves_the_queue(): void
    {
        $noBls = $this->booking(['booking' => 'SEMBL', 'bls' => 0]);
        $this->expense($noBls, 10, ['buying_rate' => 100]);
        $nothing = $this->booking(['booking' => 'NADA', 'impor_expor' => 'e']);
        $this->expense($nothing, 10, ['buying_rate' => 0]);
        $noBilling = $this->booking(['booking' => 'SEMBILLING', 'impor_expor' => 'e']);
        $this->expense($noBilling, 10, ['buying_rate' => 100, 'billing' => '']);

        $this->assertSame(0, app(GenerateOtherPaymentOrders::class)->all());

        $this->assertSame(1, (int) $noBls->fresh()->ctr_pgto_outros);
        $this->assertSame(0, (int) $nothing->fresh()->ctr_pgto_outros);
        $this->assertSame(1, (int) $noBilling->fresh()->ctr_pgto_outros);
        $this->assertSame(0, DB::table('ordem_pagamento')->count());
    }

    public function test_selection_generates_only_the_marked_expenses_and_reports_the_ignored_ones(): void
    {
        $booking = $this->booking();
        $marked = $this->expense($booking, 10, ['buying_rate' => 100]);
        $left = $this->expense($booking, 13, ['buying_rate' => 20]);
        $carrier = $this->expense($booking, 11, ['buying_rate' => 50, 'ref_due_to' => 3]);
        $generate = app(GenerateOtherPaymentOrders::class);

        $result = $generate->fromSelection($booking, [$marked, $carrier]);

        $this->assertTrue($result['success']);
        $this->assertSame('1 ordem(ns) de pagamento gerada(s) com sucesso!', $result['message']);
        $this->assertSame([['id' => $carrier, 'reason' => 'Due To, Billing ou valor de compra incompatível com este tipo de ordem.']], $result['ignored']);
        $this->assertSame([$marked], DB::table('ordem_pagamento_item')->pluck('ref_expense')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, (int) $booking->fresh()->ctr_pgto_outros);

        $again = $generate->fromSelection($booking, [$marked]);

        $this->assertFalse($again['success']);
        $this->assertSame([['id' => $marked, 'reason' => 'Despesa já lançada em ordem de pagamento.']], $again['ignored']);

        $generate->fromSelection($booking, [$left]);

        $this->assertSame(0, (int) $booking->fresh()->ctr_pgto_outros);
        $this->assertSame(2, DB::table('ordem_pagamento')->count());
    }

    public function test_selection_refuses_with_the_legacy_messages_and_changes_nothing(): void
    {
        $booking = $this->booking(['bls' => 0]);
        $expense = $this->expense($booking, 10, ['buying_rate' => 100]);
        $noCurrency = $this->expense($booking, 13, ['buying_rate' => 20, 'ref_currency_br' => null]);
        $generate = app(GenerateOtherPaymentOrders::class);

        $this->assertRefuses("O embarque {$booking->booking} não possui BLS cadastrados. Por favor revise esse processo.", fn () => $generate->fromSelection($booking, [$expense]));
        $this->assertRefuses("Despesa {$noCurrency} sem moeda de compra cadastrada.", fn () => $generate->fromSelection($booking, [$noCurrency]));
        $this->assertRefuses('Todas as despesas devem existir e pertencer à reserva informada.', fn () => $generate->fromSelection($booking, [$expense, 999]));
        $this->assertSame(0, DB::table('ordem_pagamento')->count());
    }

    public function test_equipment_check_only_counts_the_last_container_expense_as_in_the_legacy(): void
    {
        $booking = $this->booking();
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => 8, 'quantidade' => 1]);
        $missing = $this->expense($booking, 13, ['buying_rate' => 20, 'ref_equipment' => 7]);
        $present = $this->expense($booking, 13, ['buying_rate' => 20, 'ref_equipment' => 8]);
        $generate = app(GenerateOtherPaymentOrders::class);

        $this->assertTrue($generate->fromSelection($booking, [$missing, $present])['success']);

        $other = $this->booking(['booking' => 'OUTRA']);
        DB::table('mercadoria')->insert(['embarque_id' => $other->getKey(), 'tipo_container_id' => 8, 'quantidade' => 1]);
        $ok = $this->expense($other, 13, ['buying_rate' => 20, 'ref_equipment' => 8]);
        $bad = $this->expense($other, 13, ['buying_rate' => 20, 'ref_equipment' => 7]);

        $this->assertRefuses('O embarque OUTRA possui despesas com equipamentos não cadastrados. Por favor revise esse processo.', fn () => $generate->fromSelection($other, [$ok, $bad]));
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

    /** Importação marítima pendente, com chegada confirmada e um BL, salvo o que o teste mudar. @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        $bls = $attributes['bls'] ?? 1;
        $arrived = $attributes['arrived'] ?? true;
        unset($attributes['bls'], $attributes['arrived']);
        $booking = Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'BKG', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'qtd_bl' => 1, 'ctr_pgto_outros' => 1], ...$attributes]));

        for ($i = 0; $i < $bls; $i++) {
            DB::table('bl')->insert(['embarque_id' => $booking->getKey(), 'bl' => "BL{$i}"]);
        }

        if ($arrived && $booking->impor_expor === 'i') {
            DB::table('arrival_confirmation')->insert(['ref_embarque' => $booking->getKey(), 'created_by' => 1]);
        }

        return $booking;
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(Embarque $booking, int $type, array $attributes = []): int
    {
        return DB::table('fin_expenses_values')->insertGetId([...['ref_embarque' => $booking->getKey(), 'ref_expense' => $type, 'ref_due_to' => self::DISPATCHER, 'billing' => 'c', 'ref_currency_br' => 2], ...$attributes]);
    }
}
