<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AccessLinks\Pages\ManageAccessLinks;
use App\Models\LinkAcesso;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

/** Links (links do legado). */
class AccessLinkResourceTest extends TestCase
{
    use LegacySchema;

    public function test_deleting_a_link_only_marks_it_as_deleted_and_hides_it(): void
    {
        $this->actingAs($user = $this->user('auxiliares'));

        Livewire::test(ManageAccessLinks::class)
            ->callAction('create', ['descricao' => 'Portal do armador', 'link' => 'https://portal.example.com', 'login' => 'dmf', 'password' => 'segredo'])
            ->assertHasNoFormErrors();
        $link = LinkAcesso::sole();
        $this->assertSame($user->getKey(), (int) $link->created_by);

        Livewire::test(ManageAccessLinks::class)
            ->assertCanSeeTableRecords([$link])
            ->callTableAction(DeleteAction::class, $link)
            ->assertCountTableRecords(0);

        $this->assertNotNull($link->fresh()->deleted_at);
        $this->assertSame($user->getKey(), (int) $link->fresh()->deleted_by);
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
