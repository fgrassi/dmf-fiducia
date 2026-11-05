<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Airlines\Pages\ManageAirlines;
use App\Models\CiaAerea;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AirlineResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_edits_and_deletes_record(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageAirlines::class)
            ->callAction('create', ['name' => 'LATAM Cargo', 'cnpj' => '12.345.678/0001-90', 'dados_bancarios' => "Banco 1\nAg 2"])
            ->assertHasNoFormErrors();

        $record = CiaAerea::sole();
        $this->assertSame("Banco 1\nAg 2", $record->dados_bancarios);

        Livewire::test(ManageAirlines::class)
            ->callTableAction('edit', $record, ['name' => 'LATAM'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('LATAM', $record->fresh()->name);

        Livewire::test(ManageAirlines::class)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertNull($record->fresh());
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageAirlines::class)
            ->callAction('create', ['name' => ''])
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageAirlines::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
