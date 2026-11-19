<?php

namespace Tests\Feature\Filament;

use App\Actions\Maintenance\MergeRecords;
use App\Filament\Resources\BrokerExpenses\Pages\ManageBrokerExpenses;
use App\Filament\Resources\Brokers\Pages\ManageBrokers;
use App\Filament\Resources\Brokers\Pages\ViewBroker;
use App\Filament\Resources\Brokers\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Brokers\RelationManagers\PortsRelationManager;
use App\Models\Despachante;
use App\Models\DespachantePorto;
use App\Models\MatrizDespesaDespachante;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BrokerResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_edits_and_deletes_a_broker_and_a_broker_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageBrokers::class)
            ->callAction('create', ['nome' => 'Despachos SA', 'domain' => 'despachos.com'])
            ->assertHasNoFormErrors();
        $broker = Despachante::sole();

        Livewire::test(ViewBroker::class, ['record' => $broker->getKey()])
            ->assertSee('despachos.com')
            ->callAction('edit', ['nome' => 'Despachos Brasil', 'cnpj' => '12.345.678/0001-90'])
            ->assertHasNoFormErrors();
        $this->assertSame('Despachos Brasil', $broker->fresh()->nome);

        $inUse = Despachante::create(['nome' => 'Em uso']);
        DB::table('embarque')->insert(['despachante_dmf_id' => $inUse->getKey()]);
        Livewire::test(ManageBrokers::class)->assertTableActionHidden(DeleteAction::class, $inUse);

        $broker->ports()->create(['id_porto' => 1]);
        $broker->contacts()->create(['name' => 'Ana', 'email' => 'ana@despachos.com', 'status' => 1]);
        Livewire::test(ManageBrokers::class)->callTableAction(DeleteAction::class, $broker);

        $this->assertNull($broker->fresh());
        $this->assertSame(0, DB::table('despachante_contato')->count() + DB::table('despachante_porto')->count());
    }

    public function test_contact_outside_the_domain_waits_for_approval_and_can_be_edited_and_deleted(): void
    {
        $this->actingAs($user = $this->user('auxiliares'));
        $broker = Despachante::create(['nome' => 'Despachos', 'domain' => 'despachos.com']);
        $table = fn () => Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $broker, 'pageClass' => ViewBroker::class]);

        $table()
            ->callAction(TestAction::make('create')->table(), ['name' => 'Beto', 'email' => 'beto@outro.com', 'impor_expor' => 'i'])
            ->assertHasFormErrors(['justificativa' => 'required']);

        $table()
            ->callAction(TestAction::make('create')->table(), ['name' => 'Ana', 'email' => 'ana@despachos.com', 'tipo' => true, 'impor_expor' => 'e'])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('create')->table(), ['name' => 'Beto', 'email' => 'beto@outro.com', 'impor_expor' => 'i', 'justificativa' => 'Consultor externo'])
            ->assertHasNoFormErrors();

        $ana = $broker->contacts()->where('name', 'Ana')->first();
        $beto = $broker->contacts()->where('name', 'Beto')->first();
        $this->assertSame([1, 'd', 'e'], [(int) $ana->status, $ana->tipo, $ana->impor_expor]);
        $this->assertSame(0, (int) $beto->status);
        $this->assertSame($user->getKey(), (int) DB::table('justificativa_contato_despachante')->where('despachante_contato_id', $beto->getKey())->value('created_by'));

        $table()->callTableAction('edit', $ana, ['name' => 'Ana Paula', 'email' => 'ana@despachos.com', 'tipo' => false, 'impor_expor' => 'e']);
        $this->assertSame(['Ana Paula', ''], [$ana->fresh()->name, $ana->fresh()->tipo]);

        $table()->callTableAction(DeleteAction::class, $ana)->assertCanNotSeeTableRecords([$ana]);
        $this->assertSame(-1, (int) $ana->fresh()->status);
    }

    public function test_port_is_unique_per_broker_and_only_without_a_matrix_can_be_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $broker = Despachante::create(['nome' => 'Despachos']);
        $santos = DB::table('porto')->insertGetId(['nome' => 'Santos']);
        $table = fn () => Livewire::test(PortsRelationManager::class, ['ownerRecord' => $broker, 'pageClass' => ViewBroker::class]);

        $table()->callAction(TestAction::make('create')->table(), ['id_porto' => $santos])->assertHasNoFormErrors();
        $table()->callAction(TestAction::make('create')->table(), ['id_porto' => $santos])->assertHasFormErrors(['id_porto']);

        $port = $broker->ports()->sole();
        $table()->assertTableActionVisible(DeleteAction::class, $port);

        MatrizDespesaDespachante::create(['ref_despachante_porto' => $port->getKey(), 'ref_expense' => 1, 'type' => 'i', 'expense_value' => 10, 'ref_currency' => 1, 'billing' => 'p', 'due_from' => '2026-01-01', 'created_at' => '2026-01-01', 'created_by' => 1]);
        $table()->assertTableActionHidden(DeleteAction::class, $port);
    }

    public function test_new_expense_closes_the_current_one_and_warns_the_open_bookings_of_the_broker(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $broker = Despachante::create(['nome' => 'Despachos']);
        $santos = DB::table('porto')->insertGetId(['nome' => 'Santos']);
        $port = DespachantePorto::create(['id_despachante' => $broker->getKey(), 'id_porto' => $santos]);
        $expense = DB::table('fin_expenses')->insertGetId(['name' => 'Capatazia']);
        $client = DB::table('pessoa')->insertGetId(['nome' => 'Cliente SA']);
        $currency = DB::table('cmn_currencies')->insertGetId(['name' => 'Real', 'symbol' => 'R$']);
        $booking = DB::table('embarque')->insertGetId(['booking' => 'BKG1', 'cliente_id' => $client, 'despachante_id' => $broker->getKey(), 'porto_destino_id' => $santos, 'chegada_prevista' => '2026-12-10', 'finalizado' => 'n']);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $booking, 'ref_expense' => $expense]);

        $form = ['ref_expense' => $expense, 'ref_currency' => $currency, 'billing' => 'p', 'type' => 'i', 'expense_value' => 100, 'due_from' => '2026-01-01'];

        Livewire::test(ManageBrokerExpenses::class, ['brokerPort' => $port->getKey()])
            ->callAction('create', $form)
            ->assertHasNoFormErrors();
        $this->assertSame([], $this->sentSubjects()->all());

        Livewire::test(ManageBrokerExpenses::class, ['brokerPort' => $port->getKey()])
            ->callAction('create', [...$form, 'expense_value' => 120, 'due_from' => '2026-11-01'])
            ->assertHasNoFormErrors();

        $this->assertSame(['2026-11-01', null], MatrizDespesaDespachante::orderBy('id')->pluck('due_to')->all());
        $this->assertSame(['Atualizacão de taxas'], $this->sentSubjects()->all());
        $this->assertStringContainsString('BKG1 (Capatazia) - Cliente SA', $this->sentHtml());
    }

    public function test_merge_moves_everything_to_the_target_broker(): void
    {
        $target = Despachante::create(['nome' => 'Despachos']);
        $source = Despachante::create(['nome' => 'Despachos ']);
        $source->contacts()->create(['name' => 'Ana', 'email' => 'ana@x.com', 'status' => 1]);
        $booking = DB::table('embarque')->insertGetId(['despachante_id' => $source->getKey(), 'despachante_dmf_id' => $source->getKey()]);

        app(MergeRecords::class)('broker', [$source->getKey()], $target->getKey());

        $this->assertNull($source->fresh());
        $this->assertSame(1, $target->contacts()->count());
        $this->assertSame([$target->getKey(), $target->getKey()], [(int) DB::table('embarque')->where('embarque_id', $booking)->value('despachante_id'), (int) DB::table('embarque')->where('embarque_id', $booking)->value('despachante_dmf_id')]);
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
