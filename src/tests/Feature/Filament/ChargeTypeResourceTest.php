<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ChargeTypes\Pages\ManageChargeTypes;
use App\Models\FinChargesTypes;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ChargeTypeResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_unused_type(): void
    {
        $this->actingAs($this->user('admin'));

        Livewire::test(ManageChargeTypes::class)
            ->callAction('create', ['name' => 'Frete'])
            ->assertHasNoFormErrors();

        $type = FinChargesTypes::sole();

        Livewire::test(ManageChargeTypes::class)
            ->callTableAction(DeleteAction::class, $type);

        $this->assertNull($type->fresh());
    }

    public function test_type_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $type = FinChargesTypes::create(['name' => 'Frete']);
        DB::table('fin_charges')->insert(['ref_type' => $type->id, 'ref_booking' => 1, 'created_at' => now()]);

        Livewire::test(ManageChargeTypes::class)
            ->assertTableActionHidden(DeleteAction::class, $type);
    }

    public function test_requires_admin_credential(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageChargeTypes::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
