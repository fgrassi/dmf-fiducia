<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\ExportTaReport;
use App\Actions\Financeiro\GiveTaPayment;
use App\Actions\Financeiro\TaReport as TaReportQuery;
use App\Filament\Pages\TaReport;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class TaReportTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    /** Reserva com TA de 10 USD por unidade e 10 unidades: total de 100 USD. */
    private function booking(int $id, ?string $booking = null, array $shipment = [], float $unit = 10, int $quantity = 10): int
    {
        DB::table('embarque')->insert(['embarque_id' => $id, 'booking' => $booking ?? "BKG{$id}", 'finalizado' => 'n', 'saida_prevista' => '2026-11-01', ...$shipment]);
        DB::table('mercadoria')->insert(['embarque_id' => $id, 'quantidade' => $quantity]);

        return DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $id, 'ref_expense' => 1916, 'ref_due_to' => 7, 'buying_rate' => $unit, 'ref_currency_br' => 2]);
    }

    private function payment(int $expenseId, int $bookingId, float $ta, float $rate, ?int $orderId = null): void
    {
        DB::table('ta_baixa')->insert(['fin_expenses_values_id' => $expenseId, 'embarque_id' => $bookingId, 'ordem_pagamento_id' => $orderId, 'valor_ta' => $ta, 'taxa' => $rate, 'valor_pago' => $ta * $rate, 'data_pagamento' => '2026-10-10']);
    }

    /** @return list<string> */
    private function bookings(array $filters = []): array
    {
        return app(TaReportQuery::class)($filters)->pluck('booking')->all();
    }

    public function test_opens_for_manager_director_or_financial_with_supervisor(): void
    {
        $this->actingAs($this->user('financial'))->get(TaReport::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial,supervisor'))->get(TaReport::getUrl())->assertOk();
        $this->actingAs($this->user('manager'))->get(TaReport::getUrl())->assertOk();
        $this->actingAs($this->user('director'))->get(TaReport::getUrl())->assertOk();
    }

    public function test_lists_only_bookings_with_the_dmf_ta_expense_and_not_cancelled(): void
    {
        $this->booking(1, 'OK');
        $this->booking(2, 'CANCELLED', ['finalizado' => 'c']);
        $this->booking(3, 'NO-TA');
        DB::table('fin_expenses_values')->where('ref_embarque', 3)->update(['ref_expense' => 10]);
        $this->booking(4, 'OTHER-DUE');
        DB::table('fin_expenses_values')->where('ref_embarque', 4)->update(['ref_due_to' => 1]);

        $this->assertSame(['OK'], $this->bookings());
    }

    public function test_computes_total_balance_and_paid_status(): void
    {
        $none = $this->booking(1, 'NONE');
        $partial = $this->booking(2, 'PARTIAL');
        $full = $this->booking(3, 'FULL');
        $this->payment($partial, 2, 40, 5);
        $this->payment($full, 3, 100, 5);
        $this->payment($full, 3, 0, 6);

        $rows = app(TaReportQuery::class)()->keyBy('booking');

        $this->assertSame(100.0, $rows['NONE']['total_ta']);
        $this->assertSame('Não', $rows['NONE']['embarque_pago']);
        $this->assertSame('Parcial', $rows['PARTIAL']['embarque_pago']);
        $this->assertSame(60.0, $rows['PARTIAL']['saldo']);
        $this->assertSame(200.0, $rows['PARTIAL']['valor_pago']);
        $this->assertSame(5.0, $rows['PARTIAL']['taxa_pagamento']);
        $this->assertSame(300.0, $rows['PARTIAL']['prefill_valor_pago']);
        $this->assertSame('Sim', $rows['FULL']['embarque_pago']);
        $this->assertSame(0, (int) $rows['FULL']['saldo']);
        $this->assertSame(6.0, $rows['FULL']['taxa_pagamento']);
        $this->assertSame($none, $rows['NONE']['expense_value_id']);
    }

    public function test_filters_by_paid_and_received(): void
    {
        $this->booking(1, 'NONE');
        $partial = $this->booking(2, 'PARTIAL');
        $this->payment($partial, 2, 40, 5);
        DB::table('regra_despesa_cobranca_item')->insert(['despesa_id' => 7, 'regra_despesa_cobranca_id' => 4]);
        $freight = DB::table('fin_expenses_values')->insertGetId(['ref_expense' => 7, 'ref_embarque' => 2]);
        $charge = $this->charge(['status' => 1], ['embarque_id' => 2]);
        DB::table('fin_charges_items')->insert(['ref_charge' => $charge->id, 'ref_expense' => $freight, 'ref_currency' => 2, 'created_at' => '2026-10-20']);

        $this->assertSame(['PARTIAL'], $this->bookings(['pago' => 'p']));
        $this->assertSame(['NONE'], $this->bookings(['pago' => 'n']));
        $this->assertSame(['PARTIAL'], $this->bookings(['recebido' => 's']));
        $this->assertSame(['NONE'], $this->bookings(['recebido' => 'n']));
    }

    public function test_filters_by_booking_dates_and_confirmed_shipments(): void
    {
        $this->booking(1, 'AAA-1', ['saida_efetiva' => '2026-11-03']);
        $this->booking(2, 'BBB-2', ['saida_prevista' => '2026-12-01']);

        $this->assertSame(['BBB-2'], $this->bookings(['booking' => 'BBB']));
        $this->assertSame(['BBB-2'], $this->bookings(['etd_ini' => '2026-11-15']));
        $this->assertSame(['AAA-1'], $this->bookings(['etd_fim' => '2026-11-15']));
        $this->assertSame(['AAA-1'], $this->bookings(['embarques_confirmados' => true]));
        $this->assertSame(['AAA-1', 'BBB-2'], $this->bookings());
    }

    public function test_suggests_the_rate_and_value_of_the_open_payment_order(): void
    {
        $expense = $this->booking(1);
        $order = DB::table('ordem_pagamento')->insertGetId(['ref_embarque' => 1, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1]);
        DB::table('ordem_pagamento_item')->insert(['ordem_pagamento_id' => $order, 'ref_expense' => $expense, 'ref_currency' => 2, 'current_rate_value' => 5.5, 'item_value' => 300, 'created_at' => '2026-10-01', 'status' => 1]);

        $row = app(TaReportQuery::class)()->first();

        $this->assertTrue($row['has_op']);
        $this->assertSame(5.5, $row['taxa_sugerida']);
        $this->assertSame(300.0, $row['prefill_valor_pago']);
    }

    public function test_give_payment_creates_the_order_item_and_payment(): void
    {
        $expense = $this->booking(1);

        $result = app(GiveTaPayment::class)([1 => 250], 5, 7);

        $this->assertSame(['processed' => 1, 'errors' => []], $result);
        $payment = DB::table('ta_baixa')->first();
        $this->assertEquals(50, $payment->valor_ta);
        $this->assertEquals(250, $payment->valor_pago);
        $this->assertSame($expense, (int) $payment->fin_expenses_values_id);
        $this->assertSame(7, (int) $payment->usuario_id);
        $order = DB::table('ordem_pagamento')->first();
        $this->assertSame(1, (int) $order->ref_type);
        $this->assertSame(0, (int) $order->status);
        $this->assertEquals(250, $order->total_value);
        $this->assertSame((int) $order->ordem_pagamento_id, (int) $payment->ordem_pagamento_id);
        $item = DB::table('ordem_pagamento_item')->first();
        $this->assertEquals(250, $item->item_value);
        $this->assertEquals(5, $item->current_rate_value);
        $this->assertEquals(10, $item->unitary_value);
        $this->assertSame(10, (int) $item->indice);
    }

    public function test_give_payment_reuses_the_item_sums_payments_and_settles_the_order(): void
    {
        $this->booking(1);
        $give = app(GiveTaPayment::class);

        $give([1 => 250], 5, 7);
        $this->assertSame(0, (int) DB::table('ordem_pagamento')->value('status'));

        $give([1 => 300], 6, 7);

        $this->assertSame(1, DB::table('ordem_pagamento')->count());
        $this->assertSame(1, DB::table('ordem_pagamento_item')->count());
        $this->assertEquals(550, DB::table('ordem_pagamento_item')->value('item_value'));
        $this->assertEquals(6, DB::table('ordem_pagamento_item')->value('current_rate_value'));
        $this->assertEquals(550, DB::table('ordem_pagamento')->value('total_value'));
        $this->assertSame(1, (int) DB::table('ordem_pagamento')->value('status'));
        $this->assertSame('Sim', app(TaReportQuery::class)()->first()['embarque_pago']);
    }

    public function test_give_payment_creates_a_second_type_order_when_the_booking_already_had_one(): void
    {
        $this->booking(1);
        DB::table('ordem_pagamento')->insert(['ref_embarque' => 1, 'created_at' => '2026-10-01', 'status' => 2, 'ref_type' => 1]);

        app(GiveTaPayment::class)([1 => 50], 5, 7);

        $this->assertSame(2, (int) DB::table('ordem_pagamento')->orderByDesc('ordem_pagamento_id')->value('ref_type'));
    }

    public function test_give_payment_skips_the_bad_bookings_and_reports_them(): void
    {
        $this->booking(1, 'GOOD');
        $this->booking(2, 'OVER');

        $result = app(GiveTaPayment::class)([1 => 50, 2 => 5000, 3 => 10], 5, 7);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(['Booking OVER: o valor pago excede o saldo.', 'Booking 3: embarque não encontrado.'], $result['errors']);
        $this->assertSame(1, DB::table('ta_baixa')->count());
        $this->assertSame(1, DB::table('ordem_pagamento')->count());
    }

    public function test_give_payment_refuses_and_writes_nothing_when_no_booking_passes(): void
    {
        $this->booking(1, 'OVER');
        $give = app(GiveTaPayment::class);

        try {
            $give([1 => 5000], 5, 7);
            $this->fail('Esperava recusa.');
        } catch (DomainException $e) {
            $this->assertSame('Booking OVER: o valor pago excede o saldo.', $e->getMessage());
        }

        $this->assertSame(0, DB::table('ta_baixa')->count());
        $this->assertSame(0, DB::table('ordem_pagamento')->count());

        $this->expectExceptionMessage('Informe uma taxa de câmbio válida.');
        $give([1 => 50], 0, 7);
    }

    public function test_bulk_action_suggests_the_values_and_gives_the_payment(): void
    {
        $this->actingAs($this->user('manager'));
        $this->booking(1, 'BKG-A');
        $this->booking(2, 'BKG-B');

        Livewire::test(TaReport::class)
            ->assertDontSee(['BKG-A', 'BKG-B'])
            ->call('applyTableFilters')
            ->assertSee(['BKG-A', 'BKG-B'])
            ->selectTableRecords([1])
            ->callAction(TestAction::make('baixar')->table()->bulk(), ['taxa' => 5, 'pagamentos' => [['embarque_id' => 1, 'valor_pago' => 250]]])
            ->assertHasNoFormErrors();

        $this->assertSame([1], DB::table('ta_baixa')->pluck('embarque_id')->map(fn ($id) => (int) $id)->all());
        $this->assertEquals(50, DB::table('ta_baixa')->value('valor_ta'));
    }

    public function test_excel_has_the_columns_and_the_total_row(): void
    {
        $this->booking(1, 'BKG-A', ['saida_efetiva' => '2026-11-03']);
        $this->booking(2, 'BKG-B');

        $sheet = app(ExportTaReport::class)->build(app(TaReportQuery::class)())->getActiveSheet();

        $this->assertSame('Valor TA R$', $sheet->getCell('O1')->getValue());
        $this->assertSame('BKG-A', $sheet->getCell('B2')->getValue());
        $this->assertSame('03/11/2026', $sheet->getCell('G2')->getValue());
        $this->assertSame('Total', $sheet->getCell('A4')->getValue());
        $this->assertEquals(200, $sheet->getCell('I4')->getValue());
    }
}
