<?php

namespace Tests\Feature\Filament;

use App\Actions\Users\SaveSeller;
use App\Filament\Resources\Sellers\Pages\ManageSellers;
use App\Models\User;
use App\Models\Vendedor;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SellerResourceTest extends TestCase
{
    use LegacySchema;

    public function test_linked_users_are_synced_with_soft_delete_and_reactivation(): void
    {
        $kept = $this->user('x')->usuario_id;
        $removed = $this->user('y')->usuario_id;
        $seller = app(SaveSeller::class)(['nome' => 'Vera', 'email' => 'vera@dmf.com', 'usuarios' => [$kept, $removed]], 99);

        app(SaveSeller::class)(['nome' => 'Vera', 'email' => 'vera@dmf.com', 'usuarios' => [$kept]], 99, $seller);

        $active = fn () => DB::table('cmn_vendedor_usuario')->where('ref_vendedor', $seller->getKey())->whereNull('deleted_at')->pluck('ref_usuario')->all();
        $this->assertSame([$kept], $active());
        $this->assertSame(99, (int) DB::table('cmn_vendedor_usuario')->where('ref_usuario', $removed)->value('deleted_by'));

        app(SaveSeller::class)(['nome' => 'Vera', 'email' => 'vera@dmf.com', 'usuarios' => [$kept, $removed]], 99, $seller);

        $this->assertEqualsCanonicalizing([$kept, $removed], $active());
        $this->assertSame(2, DB::table('cmn_vendedor_usuario')->count());
    }

    public function test_delete_is_logical_and_hides_the_seller(): void
    {
        $admin = $this->user('auxiliares');
        $this->actingAs($admin);
        $seller = Vendedor::create(['nome' => 'Vera', 'email' => 'vera@dmf.com']);
        $other = Vendedor::create(['nome' => 'Rui', 'email' => 'rui@dmf.com']);

        Livewire::test(ManageSellers::class)
            ->assertCanSeeTableRecords([$seller, $other])
            ->callTableAction(DeleteAction::class, $seller)
            ->assertCanNotSeeTableRecords([$seller]);

        $this->assertNotNull($seller->fresh()->deleted_at);
        $this->assertSame($admin->usuario_id, (int) $seller->fresh()->deleted_by);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageSellers::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana '.$credentials, 'login' => 'ana'.$credentials, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
