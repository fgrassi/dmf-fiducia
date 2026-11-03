<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\VariedExpenses\Pages\ManageVariedExpenses;
use App\Filament\Resources\VariedExpenses\VariedExpenseResource;
use App\Models\FinDespesasVariadas;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class VariedExpenseResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_manager_or_financial_credential(): void
    {
        $this->actingAs($this->user('booking'))->get(VariedExpenseResource::getUrl())->assertForbidden();
    }

    public function test_allows_manager_and_financial(): void
    {
        $this->actingAs($this->user('manager'))->get(VariedExpenseResource::getUrl())->assertOk();
        $this->actingAs($this->user('financial'))->get(VariedExpenseResource::getUrl())->assertOk();
    }

    public function test_lists_newest_first_with_agent_symbol_and_status(): void
    {
        $this->actingAs($this->user('financial'));
        $old = $this->expense(['booking' => 'ANTIGA']);
        $paid = $this->expense(['booking' => 'PAGA', 'valor' => 1234.5, 'status_pagamento' => 1]);

        Livewire::test(ManageVariedExpenses::class)
            ->assertCanSeeTableRecords([$paid, $old], inOrder: true)
            ->assertSee(['Agente Um', 'US$ 1.234,50', 'Baixada', 'Aberto', 'Débito']);
    }

    public function test_searches_by_any_of_booking_mbl_hbl_or_invoice_without_running_the_text_as_sql(): void
    {
        $this->actingAs($this->user('financial'));
        $byInvoice = $this->expense(['invoice' => 'XYZ-9']);
        $other = $this->expense(['invoice' => 'OUTRA']);

        Livewire::test(ManageVariedExpenses::class)
            ->filterTable('busca', ['generico' => 'XYZ'])
            ->assertCanSeeTableRecords([$byInvoice])
            ->assertCanNotSeeTableRecords([$other])
            ->filterTable('busca', ['generico' => "' or 1=1 --"])
            ->assertCanNotSeeTableRecords([$byInvoice, $other]);
    }

    public function test_filters_by_agent_and_shipment_type(): void
    {
        $this->actingAs($this->user('financial'));
        $mine = $this->expense(['agente_id' => 5, 'impor_expor' => 'e']);
        $otherAgent = $this->expense(['agente_id' => 6, 'impor_expor' => 'e']);
        $otherType = $this->expense(['agente_id' => 5, 'impor_expor' => 'i']);

        Livewire::test(ManageVariedExpenses::class)
            ->filterTable('agente_id', 5)
            ->filterTable('impor_expor', 'e')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$otherAgent, $otherType]);
    }

    public function test_selecting_a_booking_fills_the_note_fields(): void
    {
        $this->actingAs($this->user('financial'));
        $this->booking();

        Livewire::test(ManageVariedExpenses::class)
            ->mountAction(TestAction::make('create'))
            ->set('mountedActions.0.data.embarque_id', 10)
            ->assertActionDataSet([
                'booking' => 'BKG10', 'agente_id' => 5, 'mbl' => 'MBL10', 'hbl' => 'HBL10', 'shipper' => 'Cliente Sete', 'impor_expor' => 'e',
            ]);
    }

    public function test_creates_a_note_in_open_status(): void
    {
        $this->actingAs($this->user('financial'));
        $this->booking();

        Livewire::test(ManageVariedExpenses::class)
            ->callAction(TestAction::make('create'), [
                'embarque_id' => 10, 'booking' => 'BKG10', 'agente_id' => 5, 'data_despesa' => '2026-10-10', 'tipo_nota' => '1', 'impor_expor' => 'e',
                'cmn_currencies_id' => 2, 'valor' => 55, 'invoice' => 'I1',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fin_despesas_variadas', ['booking' => 'BKG10', 'agente_id' => 5, 'tipo_nota' => '1', 'valor' => 55, 'status_pagamento' => 0, 'status_enviado' => 0]);
    }

    public function test_the_required_fields_are_enforced(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageVariedExpenses::class)
            ->callAction(TestAction::make('create'), ['booking' => ''])
            ->assertHasFormErrors(['booking' => 'required', 'agente_id' => 'required', 'data_despesa' => 'required', 'cmn_currencies_id' => 'required', 'valor' => 'required']);
    }

    public function test_edits_a_note_without_changing_its_payment_status(): void
    {
        $this->actingAs($this->user('financial'));
        $expense = $this->expense(['status_pagamento' => 1, 'valor' => 10]);

        Livewire::test(ManageVariedExpenses::class)
            ->callAction(TestAction::make('edit')->table($expense), ['valor' => 77])
            ->assertHasNoFormErrors();

        $this->assertSame([77.0, 1], [(float) $expense->fresh()->valor, $expense->fresh()->status_pagamento]);
    }

    public function test_removes_a_note(): void
    {
        $this->actingAs($this->user('financial'));
        $expense = $this->expense();

        Livewire::test(ManageVariedExpenses::class)
            ->callAction(TestAction::make('remover')->table($expense));

        $this->assertDatabaseMissing('fin_despesas_variadas', ['fin_despesas_variadas_id' => $expense->getKey()]);
    }

    private function booking(): void
    {
        $this->people();
        DB::table('pessoa')->insert(['pessoa_id' => 7, 'nome' => 'Cliente Sete']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'master_bl' => 'MBL10', 'house_bl' => 'HBL10', 'cliente_id' => 7, 'pessoa_id_representante' => 5, 'impor_expor' => 'e']);
    }

    private function people(): void
    {
        DB::table('pessoa')->insertOrIgnore([['pessoa_id' => 5, 'nome' => 'Agente Um'], ['pessoa_id' => 6, 'nome' => 'Agente Dois']]);
        DB::table('pessoa_tipo_pessoa')->insertOrIgnore([['pessoa_id' => 5, 'tipo_pessoa_id' => 1], ['pessoa_id' => 6, 'tipo_pessoa_id' => 1]]);
        DB::table('cmn_currencies')->insertOrIgnore(['id' => 2, 'name' => 'Dolar', 'symbol' => 'US$']);
    }

    /** @param array<string, mixed> $attributes */
    private function expense(array $attributes = []): FinDespesasVariadas
    {
        $this->people();

        return FinDespesasVariadas::create($attributes + [
            'agente_id' => 5, 'cmn_currencies_id' => 2, 'data_despesa' => '2026-10-10', 'booking' => 'BKG', 'valor' => 10,
            'impor_expor' => 'i', 'tipo_nota' => '2', 'status_pagamento' => 0, 'status_enviado' => 0,
        ]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
