<?php

namespace Tests\Feature\Filament;

use App\Actions\Users\SaveUser;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_user_with_hashed_password_credentials_and_company_link(): void
    {
        $this->actingAs($this->admin());
        $companyId = DB::table('pessoa')->insertGetId(['nome' => 'ACME']);
        $roleId = DB::table('cargos')->insertGetId(['nome' => 'Vendedor']);
        $typeId = DB::table('tipo_usuario')->insertGetId(['nome' => 'Exportador']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'nome' => 'Bia',
                'email' => 'bia@dmf.com',
                'login' => 'bia',
                'senha' => 'segredo',
                'status' => 1,
                'credentials' => ['booking', 'financial'],
                'vinculos' => [['pessoa_id' => $companyId, 'cargos_id' => $roleId, 'tipo_usuario_id' => $typeId]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('login', 'bia')->sole();
        $this->assertTrue(Hash::check('segredo', $user->senha));
        $this->assertSame('booking,financial', $user->credentials);
        $this->assertSame(1, DB::table('pessoa_usuario_tipo_usuario')->where('tipo_usuario_id', $typeId)->count());
    }

    public function test_blank_password_keeps_current_and_links_keep_their_ids(): void
    {
        $this->actingAs($this->admin());
        $user = User::forceCreate(['nome' => 'Bia', 'email' => 'bia@dmf.com', 'login' => 'bia', 'senha' => 'hash-atual', 'status' => 1]);
        $companyId = DB::table('pessoa')->insertGetId(['nome' => 'ACME']);
        $linkId = DB::table('pessoa_usuario')->insertGetId(['usuario_id' => $user->usuario_id, 'pessoa_id' => $companyId]);
        $otherLinkId = DB::table('pessoa_usuario')->insertGetId(['usuario_id' => $user->usuario_id, 'pessoa_id' => DB::table('pessoa')->insertGetId(['nome' => 'Outra'])]);

        app(SaveUser::class)(
            ['nome' => 'Bia Souza', 'senha' => '', 'vinculos' => [['pessoa_id' => $companyId, 'cargos_id' => null, 'tipo_usuario_id' => null]]],
            $user,
        );

        $this->assertSame('hash-atual', $user->fresh()->senha);
        $this->assertSame('Bia Souza', $user->fresh()->nome);
        $this->assertSame([$linkId], DB::table('pessoa_usuario')->where('usuario_id', $user->usuario_id)->pluck('pessoa_usuario_id')->all());
        $this->assertNull(DB::table('pessoa_usuario')->find($otherLinkId));
    }

    public function test_edit_form_loads_credentials(): void
    {
        $this->actingAs($this->admin());
        $user = User::forceCreate(['nome' => 'Bia', 'email' => 'bia@dmf.com', 'status' => 1, 'credentials' => 'booking,financial']);

        Livewire::test(EditUser::class, ['record' => $user->usuario_id])
            ->assertFormSet(['credentials' => ['booking', 'financial'], 'senha' => null]);
    }

    public function test_list_defaults_to_active_sas_and_crm_users(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $internal = User::forceCreate(['nome' => 'Interno', 'status' => 1]);
        $external = User::forceCreate(['nome' => 'Externo', 'status' => 1]);
        $deleted = User::forceCreate(['nome' => 'Apagado', 'status' => -1]);
        foreach ([[$internal, 1], [$external, 5], [$deleted, 4]] as [$user, $application]) {
            $companyId = DB::table('pessoa')->insertGetId(['nome' => 'Empresa '.$user->nome]);
            DB::table('pessoa_usuario')->insert(['usuario_id' => $user->usuario_id, 'pessoa_id' => $companyId]);
            DB::table('pessoa_aplicacao')->insert(['aplicacao_id' => $application, 'pessoa_id' => $companyId]);
        }

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$internal])
            ->assertCanNotSeeTableRecords([$external, $deleted, $admin])
            ->assertTableColumnStateSet('aplicacoes', ['Gerenciador'], $internal)
            ->filterTable('aplicacao', '4')
            ->assertCanNotSeeTableRecords([$internal]);
    }

    public function test_requires_admin_credential(): void
    {
        $this->actingAs(User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'usuario']));

        Livewire::test(ListUsers::class)->assertForbidden();
    }

    private function admin(): User
    {
        return User::forceCreate(['nome' => 'Admin', 'login' => 'admin', 'senha' => 'x', 'status' => 1, 'credentials' => 'admin']);
    }
}
