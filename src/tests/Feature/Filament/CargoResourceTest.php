<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Cargos\Pages\ManageCargos;
use App\Models\Cargo;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CargoResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_unused_record(): void
    {
        $this->actingAs($this->user('usuario'));

        Livewire::test(ManageCargos::class)
            ->callAction('create', ['nome' => 'Analista'])
            ->assertHasNoFormErrors();

        $record = Cargo::sole();

        Livewire::test(ManageCargos::class)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertNull($record->fresh());
    }

    public function test_cargo_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('usuario'));
        $record = Cargo::create(['nome' => 'Analista']);
        DB::table('pessoa_usuario')->insert(['usuario_id' => 1, 'pessoa_id' => 1, 'cargos_id' => $record->cargos_id]);

        Livewire::test(ManageCargos::class)
            ->assertTableActionHidden(DeleteAction::class, $record);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageCargos::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
