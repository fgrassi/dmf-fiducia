<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AgentBankAccounts\AgentBankAccountResource;
use App\Filament\Resources\AgentBankAccounts\Pages\ManageAgentBankAccounts;
use App\Models\AgenteBanco;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AgentBankAccountResourceTest extends TestCase
{
    use LegacySchema;

    public function test_only_manager_and_financial_open_the_page(): void
    {
        $this->actingAs($this->user('booking'))->get(AgentBankAccountResource::getUrl())->assertForbidden();
        $this->actingAs($this->user('manager'))->get(AgentBankAccountResource::getUrl())->assertOk();
        $this->actingAs($this->user('financial'))->get(AgentBankAccountResource::getUrl())->assertOk();
    }

    public function test_lists_all_accounts_and_filters_by_agent(): void
    {
        $this->actingAs($this->user('financial'));
        $mine = $this->account(['agente_id' => 5, 'nome_banco' => 'Banco A']);
        $other = $this->account(['agente_id' => 6, 'nome_banco' => 'Banco B']);

        Livewire::test(ManageAgentBankAccounts::class)
            ->assertCanSeeTableRecords([$mine, $other])
            ->assertSee(['Agente Um', 'Agente Dois'])
            ->filterTable('agente_id', 5)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_creates_an_account_for_the_agent_with_all_fields(): void
    {
        $this->actingAs($this->user('financial'));
        $this->people();

        Livewire::test(ManageAgentBankAccounts::class)
            ->callAction(TestAction::make('create'), [
                'agente_id' => 5, 'nome_banco' => 'HSBC', 'endereco_banco' => 'Rua 1', 'nome_conta' => 'Agente Ltd', 'numero_conta' => '123',
                'swift' => 'HSBCXX', 'iban' => 'GB00', 'endereco_agente' => "Av. 2\nCidade",
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('agente_banco', ['agente_id' => 5, 'nome_banco' => 'HSBC', 'swift' => 'HSBCXX', 'iban' => 'GB00', 'endereco_agente' => "Av. 2\nCidade"]);
    }

    public function test_agent_and_bank_are_required(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageAgentBankAccounts::class)
            ->callAction(TestAction::make('create'), ['nome_banco' => ''])
            ->assertHasFormErrors(['agente_id' => 'required', 'nome_banco' => 'required']);
    }

    public function test_edits_an_account_without_moving_it_to_another_agent(): void
    {
        $this->actingAs($this->user('financial'));
        $account = $this->account(['agente_id' => 5]);

        Livewire::test(ManageAgentBankAccounts::class)
            ->callAction(TestAction::make('edit')->table($account), ['nome_banco' => 'Novo Banco', 'agente_id' => 6])
            ->assertHasNoFormErrors();

        $this->assertSame(['Novo Banco', 5], [$account->fresh()->nome_banco, $account->fresh()->agente_id]);
    }

    public function test_has_no_delete_action(): void
    {
        $this->actingAs($this->user('financial'));
        $account = $this->account();

        Livewire::test(ManageAgentBankAccounts::class)
            ->assertTableActionDoesNotExist('delete', record: $account);
    }

    private function people(): void
    {
        DB::table('pessoa')->insertOrIgnore([['pessoa_id' => 5, 'nome' => 'Agente Um'], ['pessoa_id' => 6, 'nome' => 'Agente Dois']]);
        DB::table('pessoa_tipo_pessoa')->insertOrIgnore([['pessoa_id' => 5, 'tipo_pessoa_id' => 1], ['pessoa_id' => 6, 'tipo_pessoa_id' => 1]]);
    }

    /** @param array<string, mixed> $attributes */
    private function account(array $attributes = []): AgenteBanco
    {
        $this->people();

        return AgenteBanco::create($attributes + ['agente_id' => 5, 'nome_banco' => 'Banco']);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
