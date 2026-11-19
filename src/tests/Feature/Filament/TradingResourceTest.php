<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Tradings\Pages\ManageTradings;
use App\Filament\Resources\Tradings\Pages\ViewTrading;
use App\Filament\Resources\Tradings\RelationManagers\BrokersRelationManager;
use App\Filament\Resources\Tradings\RelationManagers\ContactsRelationManager;
use App\Models\Trading;
use App\Models\TradingDespachante;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class TradingResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_edits_and_deletes_trading(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageTradings::class)
            ->callAction('create', ['nome' => 'Trade SA', 'domain' => 'trade.com'])
            ->assertHasNoFormErrors();

        $trading = Trading::sole();
        $this->assertNotNull($trading->dtecadastro);

        Livewire::test(ViewTrading::class, ['record' => $trading->getKey()])
            ->assertSee('trade.com')
            ->callAction('edit', ['nome' => 'Trade Brasil', 'domain' => 'trade.com'])
            ->assertHasNoFormErrors();
        $this->assertSame('Trade Brasil', $trading->fresh()->nome);

        Livewire::test(ManageTradings::class)->callTableAction(DeleteAction::class, $trading);
        $this->assertNull($trading->fresh());
    }

    public function test_name_and_domain_are_required_and_a_trading_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageTradings::class)
            ->callAction('create', ['nome' => '', 'domain' => ''])
            ->assertHasFormErrors(['nome' => 'required', 'domain' => 'required']);

        $trading = Trading::create(['nome' => 'Em uso', 'domain' => 'x.com', 'dtecadastro' => '2026-01-01']);
        DB::table('embarque')->insert(['trading_id' => $trading->getKey()]);

        Livewire::test(ManageTradings::class)->assertTableActionHidden(DeleteAction::class, $trading);
    }

    public function test_contact_in_the_trading_domain_is_ok_and_other_domain_waits_for_approval(): void
    {
        $this->actingAs($user = $this->user('auxiliares'));
        $trading = Trading::create(['nome' => 'Trade', 'domain' => 'trade.com', 'dtecadastro' => '2026-01-01']);

        $component = Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $trading, 'pageClass' => ViewTrading::class]);

        $component
            ->callAction(TestAction::make('create')->table(), ['nome' => 'Ana', 'email' => 'ana@trade.com'])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('create')->table(), ['nome' => 'Beto', 'email' => 'beto@outro.com'])
            ->assertHasFormErrors(['justificativa' => 'required']);

        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $trading, 'pageClass' => ViewTrading::class])
            ->callAction(TestAction::make('create')->table(), ['nome' => 'Beto', 'email' => 'beto@outro.com', 'justificativa' => 'Consultor externo'])
            ->assertHasNoFormErrors();

        $this->assertSame(1, (int) $trading->contacts()->where('email', 'ana@trade.com')->value('status'));
        $pending = $trading->contacts()->where('email', 'beto@outro.com')->first();
        $this->assertSame(0, (int) $pending->status);
        $this->assertSame('Consultor externo', DB::table('justificativa_contato_trading')->where('trading_contato_id', $pending->getKey())->value('justificativa'));
        $this->assertSame($user->getKey(), (int) DB::table('justificativa_contato_trading')->value('created_by'));
    }

    public function test_deleting_a_contact_only_marks_it_as_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $trading = Trading::create(['nome' => 'Trade', 'domain' => 'trade.com', 'dtecadastro' => '2026-01-01']);
        $contact = $trading->contacts()->create(['nome' => 'Ana', 'email' => 'ana@trade.com', 'status' => 1]);

        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $trading, 'pageClass' => ViewTrading::class])
            ->callTableAction(DeleteAction::class, $contact)
            ->assertCountTableRecords(0);

        $this->assertSame(-1, (int) $contact->fresh()->status);
    }

    public function test_links_broker_and_chooses_which_of_its_contacts_receive(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $trading = Trading::create(['nome' => 'Trade', 'domain' => 'trade.com', 'dtecadastro' => '2026-01-01']);
        $broker = DB::table('despachante')->insertGetId(['nome' => 'Despachante Sul']);
        $ana = DB::table('despachante_contato')->insertGetId(['ref_despachante' => $broker, 'name' => 'Ana', 'email' => 'ana@d.com', 'status' => 1]);
        DB::table('despachante_contato')->insert(['ref_despachante' => $broker, 'name' => 'Inativo', 'email' => 'x@d.com', 'status' => 0]);

        $component = Livewire::test(BrokersRelationManager::class, ['ownerRecord' => $trading, 'pageClass' => ViewTrading::class])
            ->callAction(TestAction::make('create')->table(), ['despachante_id' => $broker])
            ->assertHasNoFormErrors();

        $link = TradingDespachante::sole();
        Livewire::test(BrokersRelationManager::class, ['ownerRecord' => $trading, 'pageClass' => ViewTrading::class])
            ->mountAction(TestAction::make('contacts')->table($link))
            ->assertActionDataSet(fn (array $data) => count($data['contacts']) === 1 && $data['contacts'][array_key_first($data['contacts'])]['contact_id'] === $ana);

        $component->callAction(TestAction::make('contacts')->table($link), ['contacts' => [['contact_id' => $ana, 'linked' => true, 'tipo' => 'f', 'relatorio' => '1']]]);

        $row = DB::table('trading_contato_despachante')->sole();
        $this->assertSame([$trading->getKey(), $broker, $ana, 'f', '1'], [(int) $row->trading_id, (int) $row->despachante_id, (int) $row->despachante_contato_id, $row->tipo, $row->relatorio]);

        $component->callAction(TestAction::make('contacts')->table($link), ['contacts' => [['contact_id' => $ana, 'linked' => false, 'tipo' => 'f', 'relatorio' => '1']]]);
        $this->assertSame(0, DB::table('trading_contato_despachante')->count());

        $otherBroker = DB::table('despachante')->insertGetId(['nome' => 'Outro']);
        $foreign = DB::table('despachante_contato')->insertGetId(['ref_despachante' => $otherBroker, 'name' => 'Alheio', 'email' => 'a@o.com', 'status' => 1]);
        $component->callAction(TestAction::make('contacts')->table($link), ['contacts' => [['contact_id' => $foreign, 'linked' => true, 'tipo' => 't', 'relatorio' => '0']]]);
        $this->assertSame(0, DB::table('trading_contato_despachante')->count());

        $component->callAction(TestAction::make('contacts')->table($link), ['contacts' => [['contact_id' => $ana, 'linked' => true, 'tipo' => 't', 'relatorio' => '0']]]);
        $component->callTableAction(DeleteAction::class, $link);
        $this->assertSame(0, DB::table('trading_despachante')->count() + DB::table('trading_contato_despachante')->count());
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
