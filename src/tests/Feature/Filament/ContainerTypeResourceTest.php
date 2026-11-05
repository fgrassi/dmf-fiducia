<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContainerTypes\Pages\ManageContainerTypes;
use App\Models\TipoContainer;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ContainerTypeResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_edits_and_deletes_record(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageContainerTypes::class)
            ->callAction('create', ['nome' => "40' High Cube", 'tipo' => '40', 'tipo_embarque' => 'm', 'iso_code' => '45G1'])
            ->assertHasNoFormErrors();

        $record = TipoContainer::sole();
        $this->assertSame('45G1', $record->iso_code);

        Livewire::test(ManageContainerTypes::class)
            ->callTableAction('edit', $record, ['tipo' => 'outro', 'iso_code' => null])
            ->assertHasNoTableActionErrors();
        $this->assertSame('outro', $record->fresh()->tipo);

        Livewire::test(ManageContainerTypes::class)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertNull($record->fresh());
    }

    public function test_validates_required_fields_and_options(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageContainerTypes::class)
            ->callAction('create', ['nome' => '', 'tipo' => 'xx', 'tipo_embarque' => 'z', 'iso_code' => '12345'])
            ->assertHasFormErrors(['nome' => 'required', 'tipo' => 'in', 'tipo_embarque' => 'in', 'iso_code' => 'max']);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageContainerTypes::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
