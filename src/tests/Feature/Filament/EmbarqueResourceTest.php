<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\Pages\ListEmbarques;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\ExpensesRelationManager;
use App\Models\Embarque;
use App\Models\FinExpensesValues;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class EmbarqueResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_booking_credential(): void
    {
        $this->actingAs($this->user('financial'))
            ->get(EmbarqueResource::getUrl())
            ->assertForbidden();
    }

    public function test_menu_shows_the_booking_group(): void
    {
        $this->actingAs($this->user('booking'))
            ->get(EmbarqueResource::getUrl())
            ->assertOk()
            ->assertSee('Booking');
    }

    public function test_list_searches_by_cliente_and_filters_by_operation(): void
    {
        $this->actingAs($this->user('booking'));
        $acme = DB::table('pessoa')->insertGetId(['nome' => 'ACME']);
        $other = DB::table('pessoa')->insertGetId(['nome' => 'OUTRA']);
        $import = $this->booking(['booking' => 'IMP1', 'impor_expor' => 'i', 'cliente_id' => $acme]);
        $export = $this->booking(['booking' => 'EXP1', 'impor_expor' => 'e', 'cliente_id' => $other]);

        Livewire::test(ListEmbarques::class)
            ->assertCanSeeTableRecords([$import, $export])
            ->searchTable('ACME')
            ->assertCanSeeTableRecords([$import])
            ->assertCanNotSeeTableRecords([$export])
            ->searchTable('')
            ->filterTable('impor_expor', 'e')
            ->assertCanSeeTableRecords([$export])
            ->assertCanNotSeeTableRecords([$import]);
    }

    public function test_view_page_renders_the_booking_details(): void
    {
        $this->actingAs($this->user('booking'));
        $id = DB::table('pessoa')->insertGetId(['nome' => 'ACME']);
        $booking = $this->booking(['booking' => 'IMP1', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'cliente_id' => $id, 'finalizado' => 'n']);

        Livewire::test(ViewEmbarque::class, ['record' => $booking->getKey()])
            ->assertOk()
            ->assertSee(['IMP1', 'Importação', 'Marítimo', 'ACME', 'Aberto']);
    }

    public function test_expenses_list_the_reservation_expenses_with_unit_bl_container_and_formatted_values(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);
        $unit = DB::table('fin_expenses_unities')->insertGetId(['name' => 'Container', 'acronym' => 'CNTR']);
        $thc = DB::table('fin_expenses')->insertGetId(['name' => 'THC', 'ref_unity' => $unit]);
        $usd = DB::table('cmn_currencies')->insertGetId(['name' => 'Dólar', 'symbol' => 'USD']);
        $bl = DB::table('bl')->insertGetId(['embarque_id' => $booking->getKey(), 'bl' => 'HBL1']);
        $container = DB::table('container')->insertGetId(['bl_id' => $bl, 'numero' => 'MSKU1234567']);
        $expense = $this->expense($booking, ['ref_expense' => $thc, 'ref_bl' => $bl, 'ref_container' => $container, 'buying_rate' => 1234.5, 'ref_currency_br' => $usd, 'billing' => 'p']);
        $other = $this->expense($this->booking(['booking' => 'OUTRA']), ['ref_expense' => $thc]);

        $this->actingAs($this->user('booking'));

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertCanSeeTableRecords([$expense])
            ->assertCanNotSeeTableRecords([$other])
            ->assertTableColumnStateSet('despesa.label', 'THC / CNTR', $expense)
            ->assertTableColumnStateSet('bl.bl', 'HBL1', $expense)
            ->assertTableColumnStateSet('container_number', 'MSKU1234567', $expense)
            ->assertTableColumnStateSet('buying_rate', 'USD 1.234,50', $expense)
            ->assertTableColumnFormattedStateSet('billing', 'PP', $expense);
    }

    public function test_expenses_show_exw_only_on_exports_and_sales_percentage_only_on_air(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']), 'pageClass' => ViewEmbarque::class])
            ->assertTableColumnVisible('flag_exw')
            ->assertTableColumnHidden('selling_rate_perc');

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'a']), 'pageClass' => ViewEmbarque::class])
            ->assertTableColumnHidden('flag_exw')
            ->assertTableColumnVisible('selling_rate_perc');
    }

    public function test_creates_edits_and_deletes_an_expense_from_the_tab(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);
        DB::table('fin_expenses')->insert(['id' => 500, 'name' => 'Documentação']);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 7, 'name' => 'DMF']);
        $user = $this->user('booking');
        $this->actingAs($user);
        $component = Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class]);

        $component
            ->callAction(TestAction::make(CreateAction::class)->table(), ['ref_expense' => 500, 'billing' => 'p', 'ref_due_to' => 7, 'ref_currency_br' => 2, 'buying_rate' => 100, 'ref_currency_sr' => 2, 'selling_rate' => 150])
            ->assertHasNoFormErrors();

        $expense = FinExpensesValues::sole();
        $this->assertSame([$booking->getKey(), 100.0, 150.0, $user->getKey()], [$expense->ref_embarque, $expense->buying_rate, $expense->selling_rate, $expense->created_by]);

        $component
            ->callAction(TestAction::make(EditAction::class)->table($expense), ['ref_expense' => 500, 'billing' => 'p', 'ref_due_to' => 7, 'ref_currency_br' => 2, 'buying_rate' => 120, 'ref_currency_sr' => 2, 'selling_rate' => 150])
            ->assertHasNoFormErrors();
        $this->assertSame(120.0, $expense->fresh()->buying_rate);

        $component->callAction(TestAction::make(DeleteAction::class)->table($expense));
        $this->assertModelMissing($expense);
    }

    public function test_shows_the_legacy_message_and_does_not_save_when_the_rule_is_broken(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'Ocean Freight']);
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']]);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 7, 'name' => 'DMF']);
        $this->actingAs($this->user('booking'));

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), ['ref_expense' => 1, 'billing' => 'p', 'ref_due_to' => 7, 'ref_currency_br' => 1, 'buying_rate' => 100, 'ref_currency_sr' => 2, 'selling_rate' => 150])
            ->assertNotified('O Frete Internacional não pode ser lançado em R$!');

        $this->assertSame(0, FinExpensesValues::count());
    }

    public function test_blocked_deletion_shows_the_message_and_keeps_the_expense(): void
    {
        $booking = $this->booking();
        $expense = $this->expense($booking, ['ref_expense' => 500]);
        DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_expense' => $expense->getKey(), 'ref_currency' => 1, 'created_at' => '2026-10-01']);
        $this->actingAs($this->user('booking'));

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make(DeleteAction::class)->table($expense))
            ->assertNotified('Não foi possível excluir essa despesa. Existe uma Cobrança associada a ela.');

        $this->assertModelExists($expense);
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'BKG'.random_int(1000, 9999)]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(Embarque $booking, array $attributes): FinExpensesValues
    {
        return FinExpensesValues::find(DB::table('fin_expenses_values')->insertGetId($attributes + ['ref_embarque' => $booking->getKey()]));
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
