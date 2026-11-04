<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Applications\ApplicationResource;
use App\Filament\Resources\Applications\Pages\ListApplications;
use App\Filament\Resources\Applications\Pages\ManageApplicationCompanies;
use App\Models\Aplicacao;
use App\Models\PessoaAplicacao;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ApplicationResourceTest extends TestCase
{
    use LegacySchema;

    public function test_lists_only_visible_applications_with_company_count(): void
    {
        $this->actingAs($this->user('admin'));
        $crm = $this->application(4, 'CRM');
        $intranet = $this->application(2, 'Intranet');
        PessoaAplicacao::create(['aplicacao_id' => 4, 'pessoa_id' => DB::table('pessoa')->insertGetId(['nome' => 'ACME'])]);

        Livewire::test(ListApplications::class)
            ->assertCanSeeTableRecords([$crm])
            ->assertCanNotSeeTableRecords([$intranet])
            ->assertTableColumnStateSet('company_links_count', 1, $crm);
    }

    public function test_links_a_company_once_and_removes_the_link(): void
    {
        $this->actingAs($this->user('admin'));
        $crm = $this->application(4, 'CRM');
        $companyId = DB::table('pessoa')->insertGetId(['nome' => 'ACME']);

        Livewire::test(ManageApplicationCompanies::class, ['record' => $crm->aplicacao_id])
            ->callAction(TestAction::make(CreateAction::class)->table(), ['pessoa_id' => $companyId])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make(CreateAction::class)->table(), ['pessoa_id' => $companyId])
            ->assertHasFormErrors(['pessoa_id']);

        $link = PessoaAplicacao::sole();

        Livewire::test(ManageApplicationCompanies::class, ['record' => $crm->aplicacao_id])
            ->assertCanSeeTableRecords([$link])
            ->callTableAction(DeleteAction::class, $link);

        $this->assertSame(0, PessoaAplicacao::count());
    }

    public function test_hidden_application_is_not_found(): void
    {
        $this->actingAs($this->user('admin'));
        $intranet = $this->application(2, 'Intranet');

        $this->get(ApplicationResource::getUrl('companies', ['record' => $intranet]))->assertNotFound();
    }

    public function test_requires_admin_credential(): void
    {
        $this->actingAs($this->user('usuario'));

        Livewire::test(ListApplications::class)->assertForbidden();
    }

    private function application(int $id, string $name): Aplicacao
    {
        DB::table('aplicacao')->insert(['aplicacao_id' => $id, 'nome' => $name]);

        return Aplicacao::find($id);
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
