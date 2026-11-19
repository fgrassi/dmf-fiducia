<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Assessorias\Pages\ManageAssessorias;
use App\Filament\Resources\Assessorias\Pages\ViewAssessoria;
use App\Filament\Resources\Assessorias\RelationManagers\ContactsRelationManager;
use App\Models\Assessoria;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AssessoriaResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_edits_and_deletes_assessoria(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageAssessorias::class)
            ->callAction('create', ['nome' => 'Assessor SA', 'domain' => 'assessor.com'])
            ->assertHasNoFormErrors();

        $assessoria = Assessoria::sole();
        $this->assertNotNull($assessoria->dtecadastro);

        Livewire::test(ViewAssessoria::class, ['record' => $assessoria->getKey()])
            ->assertSee('assessor.com')
            ->callAction('edit', ['nome' => 'Assessor Brasil', 'domain' => 'assessor.com'])
            ->assertHasNoFormErrors();
        $this->assertSame('Assessor Brasil', $assessoria->fresh()->nome);

        Livewire::test(ManageAssessorias::class)->callTableAction(DeleteAction::class, $assessoria);
        $this->assertNull($assessoria->fresh());
    }

    public function test_name_and_domain_are_required_and_a_assessoria_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageAssessorias::class)
            ->callAction('create', ['nome' => '', 'domain' => ''])
            ->assertHasFormErrors(['nome' => 'required', 'domain' => 'required']);

        $assessoria = Assessoria::create(['nome' => 'Em uso', 'domain' => 'x.com', 'dtecadastro' => '2026-01-01']);
        DB::table('embarque')->insert(['assessoria_id' => $assessoria->getKey()]);

        Livewire::test(ManageAssessorias::class)->assertTableActionHidden(DeleteAction::class, $assessoria);
    }

    public function test_contact_in_the_assessoria_domain_is_ok_and_other_domain_waits_for_approval(): void
    {
        $this->actingAs($user = $this->user('auxiliares'));
        $assessoria = Assessoria::create(['nome' => 'Assessor', 'domain' => 'assessor.com', 'dtecadastro' => '2026-01-01']);

        $component = Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $assessoria, 'pageClass' => ViewAssessoria::class]);

        $component
            ->callAction(TestAction::make('create')->table(), ['nome' => 'Ana', 'email' => 'ana@assessor.com'])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('create')->table(), ['nome' => 'Beto', 'email' => 'beto@outro.com'])
            ->assertHasFormErrors(['justificativa' => 'required']);

        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $assessoria, 'pageClass' => ViewAssessoria::class])
            ->callAction(TestAction::make('create')->table(), ['nome' => 'Beto', 'email' => 'beto@outro.com', 'justificativa' => 'Consultor externo'])
            ->assertHasNoFormErrors();

        $this->assertSame(1, (int) $assessoria->contacts()->where('email', 'ana@assessor.com')->value('status'));
        $pending = $assessoria->contacts()->where('email', 'beto@outro.com')->first();
        $this->assertSame(0, (int) $pending->status);
        $this->assertSame('Consultor externo', DB::table('justificativa_contato_assessoria')->where('assessoria_contato_id', $pending->getKey())->value('justificativa'));
        $this->assertSame($user->getKey(), (int) DB::table('justificativa_contato_assessoria')->value('created_by'));
    }

    public function test_deleting_a_contact_only_marks_it_as_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $assessoria = Assessoria::create(['nome' => 'Assessor', 'domain' => 'assessor.com', 'dtecadastro' => '2026-01-01']);
        $contact = $assessoria->contacts()->create(['nome' => 'Ana', 'email' => 'ana@assessor.com', 'status' => 1]);

        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $assessoria, 'pageClass' => ViewAssessoria::class])
            ->callTableAction(DeleteAction::class, $contact)
            ->assertCountTableRecords(0);

        $this->assertSame(-1, (int) $contact->fresh()->status);
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
