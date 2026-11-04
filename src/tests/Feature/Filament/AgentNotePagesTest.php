<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AgentNoteReversal;
use App\Filament\Pages\AgentNoteSettlement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AgentNotePagesTest extends TestCase
{
    use LegacySchema;

    public function test_only_manager_and_financial_open_the_pages(): void
    {
        foreach ([AgentNoteSettlement::class, AgentNoteReversal::class] as $page) {
            $this->actingAs($this->user('booking'))->get($page::getUrl())->assertForbidden();
            $this->actingAs($this->user('manager'))->get($page::getUrl())->assertOk();
            $this->actingAs($this->user('financial'))->get($page::getUrl())->assertOk();
        }
    }

    public function test_settlement_lists_the_open_notes_of_the_chosen_agent_and_settles_the_selected_ones(): void
    {
        $this->actingAs($this->user('financial'));
        $first = $this->debit(['name' => 'DN-1']);
        $second = $this->debit(['name' => 'DN-2']);

        Livewire::test(AgentNoteSettlement::class)
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

        Livewire::test(AgentNoteSettlement::class)
            ->filterTable('busca', ['agente_id' => 5])
            ->selectTableRecords(["d_{$note}"])
            ->callAction(TestAction::make('baixar')->table()->bulk(), ['data_pagamento' => null])
            ->assertHasFormErrors(['data_pagamento' => 'required']);

        $this->assertDatabaseHas('fin_debit_notes', ['id' => $note, 'status_pagamento' => 0]);
    }

    public function test_reversal_lists_paid_notes_in_the_period_and_reopens_the_selected_ones(): void
    {
        $this->actingAs($this->user('manager'));
        $paid = $this->debit(['name' => 'DN-PAGA', 'status_pagamento' => 1, 'data_pagamento' => '2026-10-05']);
        $this->debit(['name' => 'DN-ABERTA']);

        Livewire::test(AgentNoteReversal::class)
            ->filterTable('busca', ['agente_id' => 5])
            ->assertDontSee('DN-PAGA')
            ->filterTable('busca', ['agente_id' => 5, 'data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'])
            ->assertSee('DN-PAGA')
            ->assertDontSee('DN-ABERTA')
            ->selectTableRecords(["d_{$paid}"])
            ->callAction(TestAction::make('remover')->table()->bulk());

        $this->assertDatabaseHas('fin_debit_notes', ['id' => $paid, 'status_pagamento' => 0, 'data_pagamento' => null]);
    }

    /** @param array<string, mixed> $attributes */
    private function debit(array $attributes = []): int
    {
        DB::table('pessoa')->insertOrIgnore([['pessoa_id' => 5, 'nome' => 'Agente Um'], ['pessoa_id' => 7, 'nome' => 'Cliente Sete']]);
        DB::table('pessoa_tipo_pessoa')->insertOrIgnore(['pessoa_id' => 5, 'tipo_pessoa_id' => 1]);
        DB::table('cmn_currencies')->insertOrIgnore(['id' => 2, 'name' => 'Dolar', 'symbol' => 'US$']);
        DB::table('embarque')->insertOrIgnore(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'i', 'finalizado' => 'a', 'pessoa_id_cliente' => 7]);

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
