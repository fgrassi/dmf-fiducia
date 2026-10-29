<?php

namespace Tests\Feature\Actions\PaymentOrders\Generation;

use App\Actions\PaymentOrders\Generation\GeneratePaymentOrders;
use App\Models\Embarque;
use App\Models\FinPaymentOrder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class GeneratePaymentOrdersTest extends TestCase
{
    use LegacySchema;

    private const USD = 2;

    private const CARRIER = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-29 10:00');
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']]);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert([['id' => 10, 'name' => 'THC', 'ref_unity' => 1], ['id' => 11, 'name' => 'Frete', 'ref_unity' => 1]]);
        DB::table('armador')->insert(['armador_id' => self::CARRIER, 'nome' => 'MAERSK']);
    }

    public function test_generates_one_order_per_booking_mbl_with_todays_rate_and_marks_the_booking_paid(): void
    {
        $booking = $this->booking();
        $this->bl($booking, 'MBL1');
        $thc = $this->expense($booking, ['ref_expense' => 10, 'buying_rate' => 100]);
        $this->todayRate(5);

        $created = app(GeneratePaymentOrders::class)->forBooking($booking);

        $order = FinPaymentOrder::sole();
        $item = $order->items()->sole();
        $this->assertSame([1, 'MBL1', 1, self::CARRIER, 500.0, 0], [$created, $order->mbl, (int) $order->ref_type, (int) $order->ref_armador, (float) $order->total_value, (int) $booking->fresh()->ctr_pgto]);
        $this->assertSame([$thc, 100.0, self::USD, 5.0, 500.0], [(int) $item->ref_expense, $item->unitary_value, (int) $item->ref_currency, $item->current_rate_value, $item->item_value]);
    }

    public function test_the_only_bank_of_the_carrier_is_chosen_and_a_second_mbl_makes_an_extra_order(): void
    {
        $booking = $this->booking();
        $this->bl($booking, 'MBL1');
        $this->bl($booking, 'MBL2');
        $this->expense($booking, ['ref_expense' => 10, 'buying_rate' => 100]);
        $this->todayRate(5);
        $bank = DB::table('fin_armador_banks')->insertGetId(['ref_armador' => self::CARRIER, 'ref_bank' => 1, 'created_by' => 1]);

        $created = app(GeneratePaymentOrders::class)->forBooking($booking);

        $this->assertSame(2, $created);
        $this->assertSame([[1, $bank], [2, $bank]], FinPaymentOrder::orderBy('id')->get()->map(fn ($o) => [(int) $o->ref_type, (int) $o->fin_armador_bank_id])->all());
    }

    public function test_does_nothing_without_pending_expenses_or_with_outdated_rates(): void
    {
        $booking = $this->booking();
        $this->bl($booking, 'MBL1');
        $this->expense($booking, ['ref_expense' => 10, 'buying_rate' => 100]);

        $this->assertSame(0, app(GeneratePaymentOrders::class)->forBooking($booking));
        $this->assertSame([0, 1], [FinPaymentOrder::count(), (int) $booking->fresh()->ctr_pgto]);

        $this->todayRate(5);
        app(GeneratePaymentOrders::class)->forBooking($booking);

        $this->assertSame(0, app(GeneratePaymentOrders::class)->forBooking($booking));
        $this->assertSame(1, FinPaymentOrder::count());
    }

    public function test_automatic_mode_skips_an_invalid_booking_silently(): void
    {
        $booking = $this->booking();
        $this->expense($booking, ['ref_expense' => 10, 'buying_rate' => 100]);
        $this->todayRate(5);

        $this->assertSame(0, app(GeneratePaymentOrders::class)->forBooking($booking));
        $this->assertSame(0, FinPaymentOrder::count());
    }

    public function test_all_picks_bookings_by_confirmation_and_pending_payment_and_keeps_going_after_a_failure(): void
    {
        $this->todayRate(5);
        $good = $this->booking(['booking' => 'GOOD']);
        $this->bl($good, 'MBL-G');
        $this->expense($good, ['ref_expense' => 10, 'buying_rate' => 100]);
        DB::table('departure_confirmation')->insert(['ref_embarque' => $good->getKey(), 'created_by' => 1]);

        $noConfirmation = $this->booking(['booking' => 'NOCONF']);
        $this->bl($noConfirmation, 'MBL-N');
        $this->expense($noConfirmation, ['ref_expense' => 10, 'buying_rate' => 100]);

        $paid = $this->booking(['booking' => 'PAID', 'ctr_pgto' => 0]);
        $this->bl($paid, 'MBL-P');
        $this->expense($paid, ['ref_expense' => 10, 'buying_rate' => 100]);
        DB::table('departure_confirmation')->insert(['ref_embarque' => $paid->getKey(), 'created_by' => 1]);

        $broken = $this->booking(['booking' => 'BROKEN']);
        $this->bl($broken, 'MBL-B');
        $this->expense($broken, ['ref_expense' => 10, 'buying_rate' => 100, 'ref_currency_br' => 3, 'billing' => 'a']);
        DB::table('departure_confirmation')->insert(['ref_embarque' => $broken->getKey(), 'created_by' => 1]);

        $created = app(GeneratePaymentOrders::class)->all();

        $this->assertSame(1, $created);
        $this->assertSame(['MBL-G'], FinPaymentOrder::pluck('mbl')->all());
        $this->assertSame(1, (int) $broken->fresh()->ctr_pgto);
    }

    public function test_an_import_with_a_pa_expense_without_rate_is_rolled_back_and_the_batch_goes_on(): void
    {
        $this->todayRate(5, type: 'i');
        $broken = $this->booking(['booking' => 'IMPBAD', 'impor_expor' => 'i']);
        $this->bl($broken, 'MBL-B');
        $this->expense($broken, ['ref_expense' => 10, 'buying_rate' => 100, 'billing' => 'c']);
        $this->expense($broken, ['ref_expense' => 11, 'buying_rate' => 50, 'billing' => 'a', 'ref_currency_br' => 3]);
        $good = $this->booking(['booking' => 'IMPOK', 'impor_expor' => 'i']);
        $this->bl($good, 'MBL-G');
        $this->expense($good, ['ref_expense' => 10, 'buying_rate' => 100, 'billing' => 'c']);
        foreach ([$broken, $good] as $booking) {
            DB::table('arrival_confirmation')->insert(['ref_embarque' => $booking->getKey(), 'created_by' => 1]);
        }

        $created = app(GeneratePaymentOrders::class)->all();

        $this->assertSame([1, ['MBL-G'], 1], [$created, FinPaymentOrder::pluck('mbl')->all(), (int) $broken->fresh()->ctr_pgto]);
    }

    public function test_consolidated_bookings_with_the_same_mbl_pay_together(): void
    {
        $this->todayRate(5);
        $main = $this->booking(['booking' => 'MAIN', 'consolidado' => 's']);
        $other = $this->booking(['booking' => 'OTHER', 'consolidado' => 's']);
        $this->bl($main, 'MBL1');
        $this->bl($other, 'MBL1');
        $this->expense($main, ['ref_expense' => 10, 'buying_rate' => 100]);
        $this->expense($other, ['ref_expense' => 11, 'buying_rate' => 40]);

        $created = app(GeneratePaymentOrders::class)->forBooking($main);

        $order = FinPaymentOrder::sole();
        $this->assertSame([1, 700.0, 2], [$created, (float) $order->total_value, $order->items()->count()]);
        $this->assertSame([0, 0], [(int) $main->fresh()->ctr_pgto, (int) $other->fresh()->ctr_pgto]);
    }

    public function test_selection_creates_the_order_ignores_incompatible_and_used_expenses_and_updates_the_pending_flag(): void
    {
        $booking = $this->booking();
        $this->bl($booking, 'MBL1');
        $this->todayRate(5);
        $ok = $this->expense($booking, ['ref_expense' => 10, 'buying_rate' => 100]);
        $wrongBilling = $this->expense($booking, ['ref_expense' => 11, 'buying_rate' => 10, 'billing' => 'c']);
        $used = $this->expense($booking, ['ref_expense' => 11, 'buying_rate' => 20]);
        $previous = FinPaymentOrder::forceCreate(['ref_armador' => self::CARRIER, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1, 'ref_embarque' => $booking->getKey()]);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => $previous->getKey(), 'ref_expense' => $used, 'unitary_value' => 20, 'ref_currency' => self::USD, 'item_value' => 100, 'created_at' => '2026-10-01', 'ref_bl' => 1]);

        $result = app(GeneratePaymentOrders::class)->fromSelection($booking, [$ok, $wrongBilling, $used]);

        $this->assertTrue($result['success']);
        $this->assertSame('1 ordem(ns) de pagamento gerada(s) com sucesso!', $result['message']);
        $this->assertSame(
            [['id' => $wrongBilling, 'reason' => 'Due To, Billing ou valor de compra incompatível com este tipo de ordem.'], ['id' => $used, 'reason' => 'Despesa já lançada em ordem de pagamento.']],
            $result['ignored'],
        );
        $this->assertSame([500.0, 2], [(float) FinPaymentOrder::find($result['orders'][0])->total_value, (int) FinPaymentOrder::find($result['orders'][0])->ref_type]);
        $this->assertSame(0, (int) $booking->fresh()->ctr_pgto);
    }

    public function test_selection_with_nothing_valid_reports_it_and_changes_nothing(): void
    {
        $booking = $this->booking();
        $this->bl($booking, 'MBL1');
        $wrong = $this->expense($booking, ['ref_expense' => 11, 'buying_rate' => 10, 'billing' => 'c']);

        $result = app(GeneratePaymentOrders::class)->fromSelection($booking, [$wrong]);

        $this->assertSame([false, 'Nenhuma ordem de pagamento gerada.', 0, 1], [$result['success'], $result['message'], FinPaymentOrder::count(), (int) $booking->fresh()->ctr_pgto]);
    }

    public function test_selection_errors_keep_the_legacy_messages_and_leave_no_order(): void
    {
        $booking = $this->booking();
        $expense = $this->expense($booking, ['ref_expense' => 10, 'buying_rate' => 100]);
        $foreign = $this->expense($this->booking(['booking' => 'OTHER']), ['ref_expense' => 10, 'buying_rate' => 100]);
        $noBls = fn () => app(GeneratePaymentOrders::class)->fromSelection($booking, [$expense]);

        $this->assertRefuses($noBls, 'O embarque BKG não possui BLS cadastrados. Por favor revise esse processo.');

        $this->bl($booking, '');
        $this->assertRefuses($noBls, 'Cadastre um BL com MBL para gerar a ordem de pagamento.');

        DB::table('bl')->update(['mbl_number' => 'MBL1']);
        $booking->forceFill(['armador_id' => null, 'coloader_id' => null])->save();
        $this->assertRefuses(fn () => app(GeneratePaymentOrders::class)->fromSelection($booking->fresh(), [$expense]), 'Cadastre o armador para pagamento da reserva.');

        $booking->forceFill(['armador_id' => self::CARRIER])->save();
        $this->assertRefuses(fn () => app(GeneratePaymentOrders::class)->fromSelection($booking->fresh(), [$expense]), 'Há moedas sem cotação de pagamento atualizada para o armador.');
        $this->assertRefuses(fn () => app(GeneratePaymentOrders::class)->fromSelection($booking->fresh(), [$foreign]), 'Todas as despesas devem existir e pertencer à reserva informada.');

        $this->assertSame(0, FinPaymentOrder::count());
    }

    private function assertRefuses(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Esperava a mensagem: {$message}");
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId([
            ...['booking' => 'BKG', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'incoterm_id' => 3, 'armador_id' => self::CARRIER, 'ctr_pgto' => 1, 'consolidado' => 'n', 'qtd_bl' => 1, 'master_bl' => 'X'],
            ...$attributes,
        ]));
    }

    private function bl(Embarque $booking, string $mbl): void
    {
        DB::table('bl')->insert(['embarque_id' => $booking->getKey(), 'bl' => 'HBL'.$mbl, 'mbl_number' => $mbl]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(Embarque $booking, array $attributes): int
    {
        return DB::table('fin_expenses_values')->insertGetId([
            ...['ref_embarque' => $booking->getKey(), 'ref_due_to' => 3, 'billing' => 'p', 'ref_currency_br' => self::USD],
            ...$attributes,
        ]);
    }

    private function todayRate(float $rate, string $type = 'e'): void
    {
        $id = DB::table('fin_currencies_rates_armador_payment')->insertGetId(['ref_armador' => self::CARRIER, 'ref_currency' => self::USD, 'type' => $type, 'name' => 'x']);
        DB::table('fin_currencies_rates_armador_payment_values')->insert(['ref_currency_rate_armador' => $id, 'rate' => $rate, 'rate_date' => '2026-10-29', 'created_at' => '2026-10-29']);
    }
}
