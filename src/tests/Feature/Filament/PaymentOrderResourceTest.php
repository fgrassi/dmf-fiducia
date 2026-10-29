<?php

namespace Tests\Feature\Filament;

use App\Actions\PaymentOrders\Generation\GeneratePaymentOrders;
use App\Actions\PaymentOrders\RecalculatePaymentOrder;
use App\Filament\Resources\PaymentOrders\Pages\ListPaymentOrders;
use App\Filament\Resources\PaymentOrders\Pages\ViewPaymentOrder;
use App\Filament\Resources\PaymentOrders\PaymentOrderResource;
use App\Filament\Resources\PaymentOrders\RelationManagers\CustomRatesRelationManager;
use App\Filament\Resources\PaymentOrders\RelationManagers\InstructionsRelationManager;
use App\Filament\Resources\PaymentOrders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\PaymentOrders\RelationManagers\ReceiptsRelationManager;
use App\Filament\Resources\PaymentOrders\Tables\PaymentOrdersTable;
use App\Models\EmbEmbarqueFile;
use App\Models\FinPaymentOrder;
use App\Models\FinPaymentOrderItem;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PaymentOrderResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_financial_credential(): void
    {
        $this->actingAs($this->user('booking'))
            ->get(PaymentOrderResource::getUrl())
            ->assertForbidden();
    }

    public function test_menu_groups_both_payment_orders_under_one_parent_item(): void
    {
        $this->actingAs($this->user('financial'));
        Filament::setCurrentPanel('admin');

        $parent = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Financeiro')
            ->getItems()->first(fn ($item) => $item->getLabel() === 'Ordem de Pagamento');

        $this->assertSame(['Ordem de pagamento (armadores)', 'Ordem de pagamento (outros)'], collect($parent->getChildItems())->map->getLabel()->all());
    }

    public function test_lists_orders_newest_first_with_the_computed_total_and_filters_by_status(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'e']);
        $open = $this->order(['mbl' => 'MBL-A']);
        $paid = $this->order(['mbl' => 'MBL-B', 'status' => 1]);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => $open->getKey(), 'ref_expense' => 1, 'unitary_value' => 10, 'ref_currency' => 1, 'item_value' => 1234.5, 'created_at' => '2026-10-01', 'ref_bl' => 1]);

        Livewire::test(ListPaymentOrders::class)
            ->assertCanSeeTableRecords([$paid, $open], inOrder: true)
            ->assertSee('R$1.234,50')
            ->filterTable('status', 1)
            ->assertCanSeeTableRecords([$paid])
            ->assertCanNotSeeTableRecords([$open]);
    }

    public function test_filters_by_booking_and_mbl(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert([['embarque_id' => 10, 'booking' => 'BKG10'], ['embarque_id' => 11, 'booking' => 'XYZ11']]);
        $first = $this->order(['mbl' => 'MBL-A']);
        $second = $this->order(['mbl' => 'MBL-B', 'ref_embarque' => 11]);

        Livewire::test(ListPaymentOrders::class)
            ->filterTable('booking', ['booking' => 'XYZ'])
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('mbl', ['mbl' => 'MBL-A'])
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second]);
    }

    public function test_view_page_shows_the_order_and_changes_status_and_payment_date(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK', 'cnpj' => null]);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'e']);
        $order = $this->order(['mbl' => 'MBL-A']);

        Livewire::test(ViewPaymentOrder::class, ['record' => $order->getKey()])
            ->assertSee(['MAERSK', 'MBL-A', 'BKG10', 'O CNPJ está em branco!', 'Aberta', 'Atenção! Esse armador não possui Dados Bancários.'])
            ->assertSee(['N/D', 'Alterar data de pagamento'])
            ->callAction('alterarStatus', ['status' => 1])
            ->assertNotified('Status atualizado.')
            ->callAction(TestAction::make('alterarDataPagamento')->schemaComponent('payment_date', schema: 'infolist'), ['payment_date' => '2026-10-20'])
            ->assertNotified('Data de pagamento atualizada.');

        $this->assertSame([1, '2026-10-20'], [(int) $order->fresh()->status, $order->fresh()->payment_date->toDateString()]);
    }

    public function test_summary_shows_one_row_per_expense_with_the_last_items_values_and_the_total_of_enabled_items(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order();
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert([['id' => 10, 'name' => 'THC', 'ref_unity' => 1], ['id' => 11, 'name' => 'Frete', 'ref_unity' => 1]]);
        $thc = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10]);
        $freight = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 11]);
        $first = $this->item($order, $thc, 100, 5.5, 550);
        $last = $this->item($order, $thc, 120, 5.6, 672);
        $off = $this->item($order, $freight, 50, 5, 250);
        DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $order->getKey(), 'ref_expense' => $freight, 'created_by' => 1, 'created_at' => '2026-10-02', 'status' => 0]);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewPaymentOrder::class])
            ->assertCanSeeTableRecords([$last, $off])
            ->assertCanNotSeeTableRecords([$first])
            ->assertTableColumnFormattedStateSet('expense_total', 'R$ 1.222,00', $last)
            ->assertTableColumnFormattedStateSet('expense_total', 'R$ 0,00', $off)
            ->assertTableColumnFormattedStateSet('control', 'ON', $last)
            ->assertTableColumnFormattedStateSet('control', 'OFF', $off)
            ->assertSeeInOrder(['Subtotal', 'R$ 1.222,00']);
    }

    public function test_summary_shows_subtotal_iof_and_total_after_the_rows(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order(['valor_iof' => 10]);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert([['id' => 10, 'name' => 'THC', 'ref_unity' => 1], ['id' => 11, 'name' => 'Frete', 'ref_unity' => 1]]);
        $thc = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10]);
        $freight = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 11]);
        $this->item($order, $thc, 100, 5, 500);
        $this->item($order, $freight, 50, 5, 250);
        DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $order->getKey(), 'ref_expense' => $freight, 'created_by' => 1, 'created_at' => '2026-10-02', 'status' => 0]);

        $component = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewPaymentOrder::class]);
        $text = fn () => preg_replace('/\s+/', ' ', strip_tags($component->html()));

        $this->assertStringContainsString('Subtotal R$ 500,00 Despesas Financeiras/IOF R$ 10,00 Total R$ 510,00', $text());

        $component->callAction(TestAction::make('alterarIof')->schemaComponent('iof', schema: 'content'), ['iof' => 25.5]);

        $this->assertStringContainsString('Subtotal R$ 500,00 Despesas Financeiras/IOF R$ 25,50 Total R$ 525,50', $text());
    }

    public function test_summary_edits_the_rate_and_the_unit_value_and_toggles_the_expense(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order(['mbl' => 'MBL1']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert(['id' => 10, 'name' => 'THC', 'ref_unity' => 1]);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10]);
        $item = $this->item($order, $expense, 100, 5, 500);
        $component = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewPaymentOrder::class]);

        $component->callAction(TestAction::make('taxa')->table($item), ['rate' => 5.5])->assertNotified('Valor do item: R$ 550,00');
        $component->callAction(TestAction::make('valorUnitario')->table($item), ['unit' => 200])->assertNotified('Valor do item: R$ 1.100,00');
        $component->callAction(TestAction::make('alternar')->table($item))->assertNotified('Despesa desligada.');

        $this->assertSame([5.5, 200.0, 1100.0, 0], [$item->fresh()->current_rate_value, $item->fresh()->unitary_value, $item->fresh()->item_value, (int) DB::table('fin_payment_order_items_disabled')->value('status')]);
    }

    public function test_negotiated_rates_are_added_and_removed_from_the_order_page(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        $order = $this->order();
        $component = Livewire::test(CustomRatesRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewPaymentOrder::class]);

        $component
            ->callAction(TestAction::make(CreateAction::class)->table(), ['ref_currency' => 2, 'custom_rate' => 5.25, 'note' => 'acordo'])
            ->assertHasNoFormErrors();

        $rate = $order->customRates()->sole();
        $this->assertSame([5.25, 'acordo'], [$rate->custom_rate, $rate->note]);

        $component
            ->assertSee('Dólar (USD)')
            ->callAction(TestAction::make(DeleteAction::class)->table($rate));

        $this->assertSame(0, $order->customRates()->count());
    }

    public function test_recalculate_and_delete_buttons_call_the_actions(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order();
        $this->mock(RecalculatePaymentOrder::class)->shouldReceive('__invoke')->once()->andReturn(1234.5);

        Livewire::test(ViewPaymentOrder::class, ['record' => $order->getKey()])
            ->callAction('recalcular')
            ->assertNotified('Cobrança recalculada com sucesso!')
            ->callAction('excluir')
            ->assertRedirect(PaymentOrderResource::getUrl());

        $this->assertModelMissing($order);
    }

    public function test_recalculation_failure_shows_the_message_and_keeps_the_order(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order(['status' => 1]);

        Livewire::test(ViewPaymentOrder::class, ['record' => $order->getKey()])
            ->callAction('recalcular')
            ->assertNotified('Não é possível recalcular pagamentos pagas.');

        $this->assertModelExists($order);
    }

    public function test_bulk_receipt_pays_the_selected_orders_and_lists_the_total_in_the_modal(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10']);
        $first = $this->order(['mbl' => 'MBL-A']);
        $second = $this->order(['mbl' => 'MBL-B']);
        $untouched = $this->order(['mbl' => 'MBL-C']);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => $first->getKey(), 'ref_expense' => 1, 'unitary_value' => 10, 'ref_currency' => 1, 'item_value' => 100.5, 'created_at' => '2026-10-01', 'ref_bl' => 1]);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => $second->getKey(), 'ref_expense' => 1, 'unitary_value' => 10, 'ref_currency' => 1, 'item_value' => 200, 'created_at' => '2026-10-01', 'ref_bl' => 1]);

        $summary = PaymentOrdersTable::selectionSummary(FinPaymentOrder::query()->whereKey([$first->getKey(), $second->getKey()])
            ->addSelect(['items_total' => DB::table('fin_payment_order_items as i')->selectRaw('SUM(i.item_value)')->whereColumn('i.ref_payment_order', 'fin_payment_orders.id')])
            ->with('embarque')->get());

        $this->assertSame('Booking: BKG10 | MBL: MBL-A<br>Booking: BKG10 | MBL: MBL-B<br><strong>Total selecionado: R$ 300,50</strong>', $summary->toHtml());

        Livewire::test(ListPaymentOrders::class)
            ->selectTableRecords([$first->getKey(), $second->getKey()])
            ->callAction(TestAction::make('comprovante')->table()->bulk(), ['payment_date' => '2026-10-27'])
            ->assertHasFormErrors(['receipt' => 'required']);

        Livewire::test(ListPaymentOrders::class)
            ->selectTableRecords([$first->getKey(), $second->getKey()])
            ->callAction(TestAction::make('comprovante')->table()->bulk(), ['payment_date' => '2026-10-27', 'receipt' => UploadedFile::fake()->create('comp.pdf', 10, 'application/pdf')])
            ->assertNotified('Comprovante anexado e baixa realizada.');

        $this->assertSame([[1, 1, 0]], [[(int) $first->fresh()->status, (int) $second->fresh()->status, (int) $untouched->fresh()->status]]);
        $this->assertSame(1, EmbEmbarqueFile::count());
    }

    public function test_receipts_are_listed_on_the_order_and_can_be_unlinked(): void
    {
        $this->actingAs($this->user('financial'));
        $order = $this->order();
        $file = DB::table('cmn_files')->insertGetId(['name' => 'emb_BKG10_a.pdf', 'created_at' => '2026-10-27']);
        $link = EmbEmbarqueFile::forceCreate(['ref_embarque' => 10, 'ref_file' => $file, 'ref_file_type' => 54]);
        $other = EmbEmbarqueFile::forceCreate(['ref_embarque' => 10, 'ref_file' => $file, 'ref_file_type' => 24]);

        Livewire::test(ReceiptsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewPaymentOrder::class])
            ->assertCanSeeTableRecords([$link])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('27/10/2026')
            ->callAction(TestAction::make(DeleteAction::class)->table($link));

        $this->assertSame([0, 1], [EmbEmbarqueFile::where('ref_file_type', 54)->count(), EmbEmbarqueFile::count()]);
    }

    public function test_generates_lists_edits_the_iof_downloads_and_deletes_an_instruction(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK', 'cnpj' => '111']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'e']);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert(['id' => 10, 'name' => 'THC', 'ref_unity' => 1]);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10, 'ref_currency_br' => 2]);
        $order = $this->order();
        $this->item($order, $expense, 100, 5.5, 550);

        $component = Livewire::test(InstructionsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewPaymentOrder::class]);

        $component
            ->callAction(TestAction::make('gerarInstrucao')->table())
            ->assertNotified('Instrução de pagamento gerada.');

        $instruction = $order->instructions()->sole();

        $component
            ->assertSee('BRL 550,00')
            ->callAction(TestAction::make('iof')->table($instruction), ['iof' => 12.5])
            ->assertNotified('IOF atualizado.')
            ->assertSee('BRL 562,50')
            ->callAction(TestAction::make('pdf')->table($instruction))
            ->assertFileDownloaded(str_replace('/', '-', $instruction->code()).'.pdf')
            ->callAction(TestAction::make(DeleteAction::class)->table($instruction));

        $this->assertSame(0, $order->instructions()->count());
    }

    public function test_order_page_sets_the_bank_the_code_and_the_iof_and_the_tab_refuses_an_empty_instruction(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'e']);
        DB::table('cmn_banks')->insert(['id' => 1, 'name' => 'Banco X', 'bank_number' => '001', 'created_by' => 1]);
        $bank = DB::table('fin_armador_banks')->insertGetId(['ref_armador' => 1, 'ref_bank' => 1, 'bank_agency' => '0001', 'bank_account' => '123', 'created_by' => 1]);
        $order = $this->order();

        Livewire::test(ViewPaymentOrder::class, ['record' => $order->getKey()])
            ->assertSee(['Dados Bancários do Armador', 'Boleto', 'Banco X', '0001'])
            ->set('bankData.bank', $bank)
            ->assertNotified('Conta Selecionada/Atualizada com sucesso!')
            ->callAction('codigoIdentificador', ['code' => 'ABC-1']);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewPaymentOrder::class])
            ->callAction(TestAction::make('alterarIof')->schemaComponent('iof', schema: 'content'), ['iof' => 9.9])
            ->assertNotified('IOF atualizado.');

        Livewire::test(InstructionsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewPaymentOrder::class])
            ->callAction(TestAction::make('gerarInstrucao')->table())
            ->assertNotified('A ordem não tem despesas ligadas para gerar a instrução.');

        Livewire::test(ViewPaymentOrder::class, ['record' => $order->getKey()])
            ->set('bankData.bank', 0);

        $this->assertNull($order->fresh()->fin_armador_bank_id);

        $order->forceFill(['fin_armador_bank_id' => $bank])->save();
        $this->assertSame([$bank, 'ABC-1', 9.9, 0], [(int) $order->fresh()->fin_armador_bank_id, $order->fresh()->codigo_identificacao, (float) $order->fresh()->valor_iof, $order->instructions()->count()]);
    }

    public function test_list_generates_the_orders_of_every_pending_booking_or_of_one_booking(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10']);
        $mock = $this->mock(GeneratePaymentOrders::class);
        $mock->shouldReceive('all')->once()->andReturn(3);
        $mock->shouldReceive('forBooking')->once()->withArgs(fn ($booking) => $booking->getKey() === 10)->andReturn(0);

        Livewire::test(ListPaymentOrders::class)
            ->callAction('gerarManualmente')
            ->assertNotified('3 ordem(ns) de pagamento gerada(s) com sucesso!')
            ->callAction('gerarParaReserva', ['embarque_id' => 10])
            ->assertNotified('Nenhuma ordem de pagamento gerada!');
    }

    public function test_list_shows_the_generation_error_message(): void
    {
        $this->actingAs($this->user('financial'));
        $this->mock(GeneratePaymentOrders::class)->shouldReceive('all')->once()->andThrow(new \DomainException('Outra geração de ordens está em andamento. Tente novamente.'));

        Livewire::test(ListPaymentOrders::class)
            ->callAction('gerarManualmente')
            ->assertNotified('Outra geração de ordens está em andamento. Tente novamente.');
    }

    private function item(FinPaymentOrder $order, int $expense, float $unit, float $rate, float $value): FinPaymentOrderItem
    {
        return FinPaymentOrderItem::find(DB::table('fin_payment_order_items')->insertGetId([
            'ref_payment_order' => $order->getKey(), 'ref_expense' => $expense, 'unitary_value' => $unit, 'ref_currency' => 2,
            'current_rate_value' => $rate, 'item_value' => $value, 'created_at' => '2026-10-01', 'ref_bl' => 1,
        ]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function order(array $attributes = []): FinPaymentOrder
    {
        return FinPaymentOrder::find(DB::table('fin_payment_orders')->insertGetId([
            ...['ref_armador' => 1, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1, 'ref_embarque' => 10],
            ...$attributes,
        ]));
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
