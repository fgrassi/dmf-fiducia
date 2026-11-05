<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AgentNoteControl;
use App\Filament\Pages\AgentNoteSearch;
use App\Models\AgenteBanco;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AgentNotePagesTest extends TestCase
{
    use LegacySchema;

    public function test_only_manager_and_financial_open_the_page(): void
    {
        $this->actingAs($this->user('booking'))->get(AgentNoteControl::getUrl())->assertForbidden();
        $this->actingAs($this->user('manager'))->get(AgentNoteControl::getUrl())->assertOk();
        $this->actingAs($this->user('financial'))->get(AgentNoteControl::getUrl(['mode' => 'paid']))->assertOk();
    }

    public function test_settlement_lists_the_open_notes_of_the_chosen_agent_and_settles_the_selected_ones(): void
    {
        $this->actingAs($this->user('financial'));
        $first = $this->debit(['name' => 'DN-1']);
        $second = $this->debit(['name' => 'DN-2']);

        Livewire::test(AgentNoteControl::class)
            ->assertDontSee('DN-1')
            ->filterTable('busca', ['agente_id' => 5])
            ->assertSee(['DN-1', 'DN-2', 'US$ 15,00'])
            ->selectTableRecords(["d_{$first}"])
            ->callAction(TestAction::make('baixar')->table()->bulk(), ['data_pagamento' => '2026-10-20'])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fin_debit_notes', ['id' => $first, 'status_pagamento' => 1, 'data_pagamento' => '2026-10-20']);
        $this->assertDatabaseHas('fin_debit_notes', ['id' => $second, 'status_pagamento' => 0]);
    }

    public function test_settlement_requires_the_payment_date(): void
    {
        $this->actingAs($this->user('financial'));
        $note = $this->debit();

        Livewire::test(AgentNoteControl::class)
            ->filterTable('busca', ['agente_id' => 5])
            ->selectTableRecords(["d_{$note}"])
            ->callAction(TestAction::make('baixar')->table()->bulk(), ['data_pagamento' => null])
            ->assertHasFormErrors(['data_pagamento' => 'required']);

        $this->assertDatabaseHas('fin_debit_notes', ['id' => $note, 'status_pagamento' => 0]);
    }

    public function test_paid_mode_lists_the_paid_notes_in_the_period_and_reopens_the_selected_ones(): void
    {
        $this->actingAs($this->user('manager'));
        $paid = $this->debit(['name' => 'DN-PAGA', 'status_pagamento' => 1, 'data_pagamento' => '2026-10-05']);
        $this->debit(['name' => 'DN-ABERTA']);
        $this->debit(['name' => 'DN-ANTIGA', 'status_pagamento' => 1, 'data_pagamento' => '2026-08-05']);

        Livewire::test(AgentNoteControl::class, ['mode' => 'paid'])
            ->assertDontSee('DN-PAGA')
            ->filterTable('busca', ['agente_id' => 5, 'data_ini' => '2026-10-01'])
            ->assertSee('DN-PAGA')
            ->assertDontSee(['DN-ABERTA', 'DN-ANTIGA'])
            ->assertTableBulkActionHidden('baixar')
            ->selectTableRecords(["d_{$paid}"])
            ->callAction(TestAction::make('remover')->table()->bulk());

        $this->assertDatabaseHas('fin_debit_notes', ['id' => $paid, 'status_pagamento' => 0, 'data_pagamento' => null]);
    }

    public function test_the_header_buttons_switch_between_open_and_paid_notes(): void
    {
        $this->actingAs($this->user('financial'));
        $this->debit(['name' => 'DN-PAGA', 'status_pagamento' => 1, 'data_pagamento' => '2026-10-05']);
        $this->debit(['name' => 'DN-ABERTA']);

        Livewire::test(AgentNoteControl::class)
            ->filterTable('busca', ['agente_id' => 5])
            ->assertSee('DN-ABERTA')
            ->assertDontSee('DN-PAGA')
            ->call('switchMode', 'paid')
            ->assertSet('mode', 'paid')
            ->assertSee('DN-PAGA')
            ->assertDontSee('DN-ABERTA');
    }

    public function test_search_page_is_only_for_financial(): void
    {
        $this->actingAs($this->user('manager'))->get(AgentNoteSearch::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(AgentNoteSearch::getUrl())->assertOk();
    }

    public function test_paid_mode_downloads_the_pdf_of_the_chosen_account(): void
    {
        $this->actingAs($this->user('financial'));
        $this->debit(['name' => 'DN-PAGA', 'status_pagamento' => 1, 'data_pagamento' => '2026-10-05', 'impor_expor' => 'e']);
        $this->debit(['name' => 'DN-ABERTA']);
        $account = AgenteBanco::query()->create(['agente_id' => 5, 'nome_banco' => 'Banco X', 'nome_conta' => 'Agente Um LLC']);

        Livewire::test(AgentNoteControl::class, ['mode' => 'paid'])
            ->assertDontSee('DN-PAGA')
            ->assertActionHidden(TestAction::make('pdf')->table())
            ->filterTable('busca', ['agente_id' => 5])
            ->assertSee('DN-PAGA')
            ->assertDontSee('DN-ABERTA')
            ->callAction(TestAction::make('pdf')->table(), ['conta' => $account->agente_banco_id])
            ->assertFileDownloaded();
    }

    public function test_statement_pdf_requires_an_account(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(AgentNoteControl::class, ['mode' => 'paid'])
            ->filterTable('busca', ['agente_id' => 5])
            ->callAction(TestAction::make('pdf')->table(), ['conta' => null])
            ->assertHasFormErrors(['conta' => 'required']);
    }

    public function test_search_filters_notes_of_every_agent_by_type_status_and_shipper(): void
    {
        $this->actingAs($this->user('financial'));
        $this->debit(['name' => 'DN-ABERTA']);
        $this->debit(['name' => 'DN-PAGA', 'status_pagamento' => 1, 'data_pagamento' => '2026-10-05']);
        DB::table('fin_credit_notes')->insert([
            'name' => 'CN-1', 'ref_embarque' => 10, 'ref_agente' => 5, 'created_at' => '2026-10-11 10:00:00', 'created_by' => 1,
            'moeda_id' => 2, 'due_to_dmf' => 8, 'status_pagamento' => 0,
        ]);

        Livewire::test(AgentNoteSearch::class)
            ->assertSee(['DN-ABERTA', 'DN-PAGA', 'CN-1', 'Agente Um', 'US$ 8,00'])
            ->filterTable('busca', ['tipo' => 'd', 'status' => '1'])
            ->assertSee('DN-PAGA')
            ->assertDontSee(['DN-ABERTA', 'CN-1'])
            ->filterTable('busca', ['tipo' => 'c', 'shipper' => 'Cliente'])
            ->assertSee('CN-1')
            ->assertDontSee('DN-PAGA')
            ->filterTable('busca', ['shipper' => 'Outro'])
            ->assertDontSee('CN-1');
    }

    /** @param array<string, mixed> $attributes */
    private function debit(array $attributes = []): int
    {
        DB::table('pessoa')->insertOrIgnore([['pessoa_id' => 5, 'nome' => 'Agente Um'], ['pessoa_id' => 7, 'nome' => 'Cliente Sete']]);
        DB::table('pessoa_tipo_pessoa')->insertOrIgnore(['pessoa_id' => 5, 'tipo_pessoa_id' => 1]);
        DB::table('cmn_currencies')->insertOrIgnore(['id' => 2, 'name' => 'Dolar', 'symbol' => 'US$']);
        DB::table('embarque')->insertOrIgnore(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'i', 'finalizado' => 'a', 'pessoa_id_cliente' => 7]);

        $mode = $attributes['impor_expor'] ?? 'i';
        unset($attributes['impor_expor']);
        DB::table('embarque')->where('embarque_id', 10)->update(['impor_expor' => $mode]);

        return DB::table('fin_debit_notes')->insertGetId($attributes + [
            'ref_embarque' => 10, 'ref_agente' => 5, 'created_at' => '2026-10-10 10:00:00', 'created_by' => 1, 'moeda_id' => 2,
            'due_to_dmf' => 15, 'status_pagamento' => 0,
        ]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
