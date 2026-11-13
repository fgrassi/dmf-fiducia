<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Filament\Resources\Countries\Pages\ManageCountries;
use App\Filament\Resources\Ports\Pages\ManagePorts;
use App\Filament\Resources\States\Pages\ManageStates;
use App\Models\Cidade;
use App\Models\Estado;
use App\Models\Pais;
use App\Models\Porto;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class LocationResourcesTest extends TestCase
{
    use LegacySchema;

    public function test_creates_country_state_and_city(): void
    {
        $this->actingAs($this->user('localidade'));

        Livewire::test(ManageCountries::class)
            ->callAction('create', ['nome' => 'Brasil', 'uncode' => 'BR'])
            ->assertHasNoFormErrors();
        $country = Pais::sole();

        Livewire::test(ManageStates::class)
            ->callAction('create', ['nome' => 'Santa Catarina', 'uf' => 'SC', 'paises_id' => $country->paises_id])
            ->assertHasNoFormErrors();
        $state = Estado::sole();

        Livewire::test(ManageCities::class)
            ->callAction('create', ['nome' => 'Itajaí', 'estados_id' => $state->estados_id])
            ->assertHasNoFormErrors();

        $this->assertSame($state->estados_id, Cidade::sole()->estado->estados_id);
    }

    public function test_required_fields(): void
    {
        $this->actingAs($this->user('localidade'));

        Livewire::test(ManageCountries::class)
            ->callAction('create', ['nome' => ''])
            ->assertHasFormErrors(['nome' => 'required']);
        Livewire::test(ManageStates::class)
            ->callAction('create', ['nome' => '', 'paises_id' => null])
            ->assertHasFormErrors(['nome' => 'required', 'paises_id' => 'required']);
        Livewire::test(ManageCities::class)
            ->callAction('create', ['nome' => '', 'estados_id' => null])
            ->assertHasFormErrors(['nome' => 'required', 'estados_id' => 'required']);
    }

    public function test_referenced_records_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('localidade'));
        $country = Pais::create(['nome' => 'Brasil']);
        $state = Estado::create(['nome' => 'Santa Catarina', 'paises_id' => $country->paises_id]);
        $city = Cidade::create(['nome' => 'Itajaí', 'estados_id' => $state->estados_id]);

        Livewire::test(ManageCountries::class)->assertTableActionHidden(DeleteAction::class, $country);
        Livewire::test(ManageStates::class)->assertTableActionHidden(DeleteAction::class, $state);

        Livewire::test(ManageCities::class)->callTableAction(DeleteAction::class, $city);
        $this->assertNull($city->fresh());

        Livewire::test(ManageStates::class)->callTableAction(DeleteAction::class, $state);
        Livewire::test(ManageCountries::class)->callTableAction(DeleteAction::class, $country);
        $this->assertNull($country->fresh());
    }

    public function test_creates_port_and_blocks_delete_when_used_in_shipment(): void
    {
        $this->actingAs($this->user('localidade'));
        $country = Pais::create(['nome' => 'Brasil']);

        Livewire::test(ManagePorts::class)
            ->callAction('create', ['nome' => 'Itajaí', 'tipo' => 'p', 'ref_pais' => $country->paises_id, 'uncode' => 'BRITJ'])
            ->assertHasNoFormErrors();
        $port = Porto::sole();

        Livewire::test(ManagePorts::class)
            ->callAction('create', ['nome' => '', 'tipo' => null, 'ref_pais' => null])
            ->assertHasFormErrors(['nome' => 'required', 'tipo' => 'required', 'ref_pais' => 'required']);

        DB::table('embarque')->insert(['porto_descarga' => $port->porto_id]);
        Livewire::test(ManagePorts::class)->assertTableActionHidden(DeleteAction::class, $port);
        DB::table('embarque')->delete();
        Livewire::test(ManagePorts::class)->assertTableActionVisible(DeleteAction::class, $port);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageCountries::class)->assertForbidden();
        Livewire::test(ManageStates::class)->assertForbidden();
        Livewire::test(ManageCities::class)->assertForbidden();
        Livewire::test(ManagePorts::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}
