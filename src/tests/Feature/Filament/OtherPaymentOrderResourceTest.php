<?php

namespace Tests\Feature\Filament;

use App\Actions\PaymentOrders\Other\RecalculateOtherPaymentOrder;
use App\Filament\Resources\OtherPaymentOrders\OtherPaymentOrderResource;
use App\Filament\Resources\OtherPaymentOrders\Pages\ListOtherPaymentOrders;
use App\Filament\Resources\OtherPaymentOrders\Pages\ViewOtherPaymentOrder;
use App\Filament\Resources\OtherPaymentOrders\RelationManagers\InstructionsRelationManager;
use App\Filament\Resources\OtherPaymentOrders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\OtherPaymentOrders\RelationManagers\TaPaymentsRelationManager;
use App\Models\InstrucaoPagamentoOutros;
use App\Models\OrdemPagamento;
use App\Models\OrdemPagamentoItem;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class OtherPaymentOrderResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_financial_credential(): void
    {
        $this->actingAs($this->user('booking'))
            ->get(OtherPaymentOrderResource::getUrl())
            ->assertForbidden();
    }

    public function test_lists_orders_newest_first_with_client_total_and_the_last_charge_indicator(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'ACME']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'house_bl' => 'HBL10', 'cliente_id' => 1, 'impor_expor' => 'e']);
        $open = $this->order();
        $paid = $this->order(['status' => 1]);
        $this->item($open, 1, 1234.5);
        DB::table('fin_charges')->insert([
            ['ref_type' => 1, 'ref_booking' => 10, 'status' => 0, 'created_at' => '2026-10-01'],
            ['ref_type' => 1, 'ref_booking' => 10, 'status' => 1, 'created_at' => '2026-10-02'],
        ]);

        Livewire::test(ListOtherPaymentOrders::class)
            ->assertCanSeeTableRecords([$paid, $open], inOrder: true)
            ->assertSee(['ACME', 'HBL10', 'R$1.234,50', 'Cobranças pagas'])
            ->filterTable('status', 1)
            ->assertCanSeeTableRecords([$paid])
            ->assertCanNotSeeTableRecords([$open]);
    }

    public function test_filters_by_booking_mbl_recebedor_and_dates(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert([['embarque_id' => 10, 'booking' => 'BKG10', 'master_bl' => 'MBL-A'], ['embarque_id' => 11, 'booking' => 'XYZ11', 'master_bl' => null]]);
        DB::table('bl')->insert(['embarque_id' => 11, 'mbl_number' => 'MBL-B']);
        $first = $this->order(['created_at' => '2026-09-01', 'payment_date' => '2026-09-05']);
        $second = $this->order(['ref_embarque' => 11, 'created_at' => '2026-10-10']);
        DB::table('instrucao_pagamento_outros')->insert(['ordem_pagamento_id' => $second->getKey(), 'created_at' => '2026-10-11', 'recebedor' => 'Despachante Sul']);

        Livewire::test(ListOtherPaymentOrders::class)
            ->filterTable('booking', ['booking' => 'XYZ'])
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('mbl', ['mbl' => 'MBL-B'])
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('mbl', ['mbl' => 'MBL-A'])
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->resetTableFilters()
            ->filterTable('recebedor', ['recebedor' => 'Sul'])
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('created', ['from' => '2026-10-01', 'until' => null])
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('paid', ['from' => '2026-09-01', 'until' => '2026-09-30'])
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    }

    public function test_view_page_shows_the_booking_and_changes_status_and_payment_date(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'e']);
        $order = $this->order();

        Livewire::test(ViewOtherPaymentOrder::class, ['record' => $order->getKey()])
            ->assertSee(['BKG10', 'Aberta', 'N/D', 'Alterar data de pagamento'])
            ->callAction('alterarStatus', ['status' => 1])
            ->assertNotified('Status atualizado.')
            ->callAction(TestAction::make('alterarDataPagamento')->schemaComponent('payment_date', schema: 'infolist'), ['payment_date' => '2026-10-20'])
            ->assertNotified('Data de pagamento atualizada.');

        $this->assertSame([1, '2026-10-20'], [(int) $order->fresh()->status, $order->fresh()->payment_date->toDateString()]);
    }

    public function test_summary_shows_one_row_per_expense_with_the_last_items_values_and_the_totals(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order(['valor_iof' => 10]);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert([['id' => 10, 'name' => 'Despacho', 'ref_unity' => 1], ['id' => 11, 'name' => 'Seguro', 'ref_unity' => 1]]);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 5, 'name' => 'Despachante']);
        $dispatch = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10, 'ref_due_to' => 5]);
        $insurance = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 11, 'ref_due_to' => 5]);
        $first = $this->item($order, $dispatch, 100);
        $last = $this->item($order, $dispatch, 300, status: 0, indice: 4);
        $other = $this->item($order, $insurance, 50);
        $component = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOtherPaymentOrder::class]);

        $component
            ->assertCanSeeTableRecords([$last, $other])
            ->assertCanNotSeeTableRecords([$first])
            ->assertTableColumnFormattedStateSet('item_value', 'R$ 300,00', $last)
            ->assertTableColumnFormattedStateSet('status', 'ON', $other)
            ->assertTableColumnFormattedStateSet('status', 'OFF', $last);
        $text = preg_replace('/\s+/', ' ', strip_tags($component->html()));
        $this->assertStringContainsString('Subtotal R$ 450,00 Despesas Financeiras/IOF R$ 10,00 Total R$ 460,00', $text);

        $component->callAction(TestAction::make('alterarIof')->schemaComponent('iof', schema: 'content'), ['iof' => 20.5]);

        $this->assertStringContainsString('Despesas Financeiras/IOF R$ 20,50 Total R$ 470,50', preg_replace('/\s+/', ' ', strip_tags($component->html())));
    }

    public function test_summary_edits_rate_unit_and_index_and_toggles_the_expense(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order();
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert(['id' => 10, 'name' => 'Despacho', 'ref_unity' => 1]);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10, 'ref_due_to' => 5]);
        $item = $this->item($order, $expense, 100, indice: 2);
        $component = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOtherPaymentOrder::class]);

        $component->callAction(TestAction::make('taxa')->table($item), ['rate' => 6])->assertNotified('Valor do item: R$ 120,00');
        $component->callAction(TestAction::make('valorUnitario')->table($item), ['unit' => 110])->assertNotified('Valor do item: R$ 1.320,00');
        $component->callAction(TestAction::make('indice')->table($item), ['index' => 3])->assertNotified('Valor do item: R$ 1.980,00');
        $component->callAction(TestAction::make('alternar')->table($item))->assertNotified('Despesa desligada.');

        $this->assertSame([6.0, 110.0, 1980.0, null], [$item->fresh()->current_rate_value, $item->fresh()->unitary_value, $item->fresh()->item_value, $item->fresh()->status]);
    }

    public function test_recalculate_button_sends_the_rates_and_parties_and_reports_the_result(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order();
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert(['id' => 10, 'name' => 'Despacho', 'ref_unity' => 1]);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 5, 'name' => 'Despachante']);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10, 'ref_due_to' => 5]);
        $this->item($order, $expense, 100, indice: 1);
        $this->mock(RecalculateOtherPaymentOrder::class)
            ->shouldReceive('__invoke')
            ->once()
            ->withArgs(fn (OrdemPagamento $o, array $rates, bool $reset, array $parties, int $user) => $o->is($order) && $rates === [2 => 5.5] && $reset === true && $parties[5]['bank'] === 'Banco X')
            ->andReturn(['status' => 'OK', 'message' => 'Instrução de pagamento atualizada com sucesso']);

        Livewire::test(ViewOtherPaymentOrder::class, ['record' => $order->getKey()])
            ->callAction('recalcular', ['rates' => [2 => '5.5'], 'reset' => true, 'parties' => [5 => ['id' => null, 'name' => 'Despachante Sul', 'cnpj' => '1', 'bank' => 'Banco X']]])
            ->assertNotified('Instrução de pagamento atualizada com sucesso');
    }

    public function test_recalculate_requires_the_bank_data_and_the_rate_and_shows_the_closed_order_message(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order();
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert(['id' => 10, 'name' => 'Despacho', 'ref_unity' => 1]);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 5, 'name' => 'Despachante']);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10, 'ref_due_to' => 5]);
        $this->item($order, $expense, 100, indice: 1);

        Livewire::test(ViewOtherPaymentOrder::class, ['record' => $order->getKey()])
            ->callAction('recalcular', ['rates' => [2 => ''], 'parties' => [5 => ['bank' => '']]])
            ->assertHasFormErrors(['rates.2' => 'required', 'parties.5.bank' => 'required']);

        $closed = $this->order(['status' => 1]);
        $this->item($closed, $expense, 100, indice: 1);

        Livewire::test(ViewOtherPaymentOrder::class, ['record' => $closed->getKey()])
            ->callAction('recalcular', ['rates' => [2 => '5'], 'parties' => [5 => ['name' => 'X', 'bank' => 'B']]])
            ->assertNotified('A ordem de pagamento já foi paga ou cancelada.');
    }

    public function test_delete_button_removes_the_order_and_goes_back_to_the_list(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order();

        Livewire::test(ViewOtherPaymentOrder::class, ['record' => $order->getKey()])
            ->callAction('excluir')
            ->assertRedirect(OtherPaymentOrderResource::getUrl());

        $this->assertModelMissing($order);
    }

    public function test_ta_payments_tab_only_on_ta_orders_and_edits_and_deletes_payments_without_capa(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('mercadoria')->insert(['embarque_id' => 10, 'quantidade' => 10]);
        $plain = $this->order();
        $order = $this->order();
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 1916, 'buying_rate' => 10, 'ref_currency_br' => 2]);
        $itemId = DB::table('ordem_pagamento_item')->insertGetId([
            'ordem_pagamento_id' => $order->getKey(), 'ref_expense' => $expense, 'unitary_value' => 10, 'ref_currency' => 2,
            'current_rate_value' => 5, 'item_value' => 0, 'created_at' => '2026-10-01', 'status' => 0, 'indice' => 1,
        ]);
        $payment = fn (array $attributes) => DB::table('ta_baixa')->insertGetId([
            ...['fin_expenses_values_id' => $expense, 'embarque_id' => 10, 'ordem_pagamento_id' => $order->getKey(), 'ordem_pagamento_item_id' => $itemId, 'valor_ta' => 20, 'taxa' => 5, 'valor_pago' => 100, 'data_pagamento' => '2026-10-10', 'usuario_id' => auth()->id()],
            ...$attributes,
        ]);
        $editable = $payment([]);
        $linked = $payment(['capa_processo_despesa_item_id' => 4, 'valor_ta' => 10, 'valor_pago' => 50, 'data_pagamento' => '2026-10-11']);

        $this->assertFalse(TaPaymentsRelationManager::canViewForRecord($plain, ViewOtherPaymentOrder::class));
        $this->assertTrue(TaPaymentsRelationManager::canViewForRecord($order, ViewOtherPaymentOrder::class));

        $table = Livewire::test(TaPaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOtherPaymentOrder::class])
            ->assertCanSeeTableRecords([$editable, $linked], inOrder: true)
            ->assertTableActionVisible('alterar', $editable)
            ->assertTableActionHidden('alterar', $linked)
            ->assertTableActionHidden('excluir', $linked)
            ->callAction(TestAction::make('alterar')->table($editable), ['rate' => 4, 'paid' => 200, 'date' => '2026-10-20'])
            ->assertNotified('Baixa atualizada.');

        $this->assertEquals(50, DB::table('ta_baixa')->where('ta_baixa_id', $editable)->value('valor_ta'));
        $this->assertEquals(250, DB::table('ordem_pagamento')->where('ordem_pagamento_id', $order->getKey())->value('total_value'));

        $table->callAction(TestAction::make('excluir')->table($editable))->assertNotified('Baixa excluída.');

        $this->assertDatabaseMissing('ta_baixa', ['ta_baixa_id' => $editable]);
        $this->assertDatabaseHas('ta_baixa', ['ta_baixa_id' => $linked]);
    }

    public function test_instructions_tab_lists_the_parties_with_total_edits_the_iof_downloads_the_pdf_and_deletes(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'e']);
        $order = $this->order();
        $one = DB::table('instrucao_pagamento_outros')->insertGetId(['ordem_pagamento_id' => $order->getKey(), 'created_at' => '2026-10-02 09:00:00', 'valor_iof' => 10, 'recebedor' => 'Despachante Sul', 'cnpj_recebedor' => '99', 'dados_bancarios' => 'Banco 1']);
        $two = DB::table('instrucao_pagamento_outros')->insertGetId(['ordem_pagamento_id' => $order->getKey(), 'created_at' => '2026-10-02 09:00:00', 'valor_iof' => 0, 'recebedor' => 'Seguradora']);
        $item = $this->item($order, 1, 100)->getKey();
        DB::table('instrucao_pagamento_item_outros')->insert([
            ['instrucao_pagamento_outros_id' => $one, 'ordem_pagamento_item_id' => $item, 'valor' => 550],
            ['instrucao_pagamento_outros_id' => $two, 'ordem_pagamento_item_id' => $item, 'valor' => 40],
        ]);
        $instruction = InstrucaoPagamentoOutros::find($one);

        $component = Livewire::test(InstructionsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOtherPaymentOrder::class])
            ->assertSee(['Despachante Sul', 'Seguradora', 'BRL 550,00', 'BRL 560,00', 'R$ 600,00'])
            ->callAction(TestAction::make('iof')->table($instruction), ['iof' => 15])
            ->assertNotified('IOF atualizado.')
            ->assertSee(['BRL 565,00', 'R$ 605,00']);

        $component
            ->callAction(TestAction::make('pdf')->table($instruction))
            ->assertFileDownloaded(str_replace('/', '-', $instruction->code()).'.pdf')
            ->callAction(TestAction::make(DeleteAction::class)->table($instruction));

        $this->assertSame([$two], $order->instructions()->pluck('instrucao_pagamento_outros_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, DB::table('instrucao_pagamento_item_outros')->count());
    }

    /** @param  array<string, mixed>  $attributes */
    private function order(array $attributes = []): OrdemPagamento
    {
        return OrdemPagamento::find(DB::table('ordem_pagamento')->insertGetId([
            ...['ref_embarque' => 10, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1],
            ...$attributes,
        ]));
    }

    private function item(OrdemPagamento $order, int $expense, float $value, int $status = 1, ?int $indice = null): OrdemPagamentoItem
    {
        return OrdemPagamentoItem::find(DB::table('ordem_pagamento_item')->insertGetId([
            'ordem_pagamento_id' => $order->getKey(), 'ref_expense' => $expense, 'unitary_value' => 10, 'ref_currency' => 2,
            'current_rate_value' => 5, 'item_value' => $value, 'created_at' => '2026-10-01', 'status' => $status, 'indice' => $indice,
        ]));
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
