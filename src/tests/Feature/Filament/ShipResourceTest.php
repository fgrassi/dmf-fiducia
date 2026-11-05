<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Ships\Pages\ManageShips;
use App\Models\Navio;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ShipResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_unused_record(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageShips::class)
            ->callAction('create', ['nome' => 'MSC Aurora', 'tamanho' => 366.5])
            ->assertHasNoFormErrors();

        $record = Navio::sole();
        $this->assertSame(366.5, $record->tamanho);

        Livewire::test(ManageShips::class)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertNull($record->fresh());
    }

    public function test_name_and_size_are_required(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageShips::class)
            ->callAction('create', ['nome' => '', 'tamanho' => null])
            ->assertHasFormErrors(['nome' => 'required', 'tamanho' => 'required']);
    }

    public function test_ship_used_as_origin_or_destination_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $origin = Navio::create(['nome' => 'A', 'tamanho' => 1]);
        $destination = Navio::create(['nome' => 'B', 'tamanho' => 1]);
        DB::table('embarque')->insert(['navio_id' => $origin->navio_id, 'navio_id_destino' => $destination->navio_id]);

        Livewire::test(ManageShips::class)
            ->assertTableActionHidden(DeleteAction::class, $origin)
            ->assertTableActionHidden(DeleteAction::class, $destination);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageShips::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
