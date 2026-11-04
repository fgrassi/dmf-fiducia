<?php

namespace Tests\Feature\Filament;

use App\Actions\Users\SaveUserType;
use App\Filament\Resources\UserTypes\Pages\ManageUserTypes;
use App\Models\TipoUsuario;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class UserTypeResourceTest extends TestCase
{
    use LegacySchema;

    public function test_new_application_links_get_default_items_and_existing_ones_keep_their_permissions(): void
    {
        $kept = DB::table('pessoa_aplicacao')->insertGetId(['aplicacao_id' => 1, 'pessoa_id' => 1]);
        $added = DB::table('pessoa_aplicacao')->insertGetId(['aplicacao_id' => 4, 'pessoa_id' => 1]);
        $dropped = DB::table('pessoa_aplicacao')->insertGetId(['aplicacao_id' => 1, 'pessoa_id' => 2]);

        $type = app(SaveUserType::class)(['nome' => 'Agente', 'aplicacoes' => [$kept, $dropped]]);
        $keptLink = DB::table('pessoa_aplicacao_tipo_usuario')->where('pessoa_aplicacao_id', $kept)->value('pessoa_apli_tipo_usu_id');
        DB::table('item_aplicacao')->where('pessoa_apli_tipo_usu_id', $keptLink)->where('item_id', 3)->update(['gravacao' => 0]);

        app(SaveUserType::class)(['nome' => 'Agente', 'aplicacoes' => [$kept, $added]], $type);

        $links = DB::table('pessoa_aplicacao_tipo_usuario')->where('tipo_usuario_id', $type->getKey())->pluck('pessoa_apli_tipo_usu_id', 'pessoa_aplicacao_id');
        $this->assertEqualsCanonicalizing([$kept, $added], $links->keys()->all());
        $this->assertSame($keptLink, $links[$kept]);
        $this->assertSame(0, (int) DB::table('item_aplicacao')->where('pessoa_apli_tipo_usu_id', $keptLink)->where('item_id', 3)->value('gravacao'));
        $this->assertSame([1, 3, 4], DB::table('item_aplicacao')->where('pessoa_apli_tipo_usu_id', $links[$added])->orderBy('item_id')->pluck('item_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(6, DB::table('item_aplicacao')->count());
    }

    public function test_deleting_a_type_removes_its_application_links(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $companyApplication = DB::table('pessoa_aplicacao')->insertGetId(['aplicacao_id' => 1, 'pessoa_id' => 1]);
        $type = app(SaveUserType::class)(['nome' => 'Agente', 'aplicacoes' => [$companyApplication]]);

        Livewire::test(ManageUserTypes::class)
            ->callTableAction(DeleteAction::class, $type);

        $this->assertNull($type->fresh());
        $this->assertSame(0, DB::table('pessoa_aplicacao_tipo_usuario')->count());
        $this->assertSame(0, DB::table('item_aplicacao')->count());
    }

    public function test_type_in_use_by_a_user_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $type = TipoUsuario::create(['nome' => 'Agente']);
        DB::table('pessoa_usuario_tipo_usuario')->insert(['pessoa_usuario_id' => 1, 'tipo_usuario_id' => $type->tipo_usuario_id]);

        Livewire::test(ManageUserTypes::class)
            ->assertTableActionHidden(DeleteAction::class, $type);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageUserTypes::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
