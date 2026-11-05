<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CompanyGroups\Pages\CreateCompanyGroup;
use App\Filament\Resources\CompanyGroups\Pages\EditCompanyGroup;
use App\Filament\Resources\CompanyGroups\RelationManagers\MembersRelationManager;
use App\Models\GrupoEmpresa;
use App\Models\GrupoEmpresaMembro;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CompanyGroupResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_group(): void
    {
        $this->actingAs($this->user('admin'));

        Livewire::test(CreateCompanyGroup::class)
            ->fillForm(['nome' => 'Grupo FASA'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Grupo FASA', GrupoEmpresa::sole()->nome);
    }

    public function test_adds_member_with_author_and_date_then_removes_it(): void
    {
        $user = $this->user('admin');
        $this->actingAs($user);
        $group = GrupoEmpresa::create(['nome' => 'Grupo FASA']);
        $companyId = DB::table('pessoa')->insertGetId(['nome' => 'Cliente A']);

        $manager = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditCompanyGroup::class]);

        $manager->callTableAction(CreateAction::class, data: ['pessoa_id' => $companyId])
            ->assertHasNoFormErrors();

        $member = GrupoEmpresaMembro::sole();
        $this->assertSame($group->grupo_empresa_id, (int) $member->grupo_empresa_id);
        $this->assertSame($companyId, (int) $member->pessoa_id);
        $this->assertSame($user->getKey(), (int) $member->created_by);
        $this->assertNotNull($member->created_at);

        $manager->callTableAction(DeleteAction::class, $member);

        $this->assertSame(0, GrupoEmpresaMembro::count());
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(CreateCompanyGroup::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
