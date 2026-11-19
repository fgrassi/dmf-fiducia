<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\InsuranceCompanies\Pages\ManageInsuranceCompanies;
use App\Models\CiaSeguro;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

/** Cias Seguro (ciaSeguro do legado). */
class InsuranceCompanyResourceTest extends TestCase
{
    use LegacySchema;

    public function test_insurance_company_is_created_and_name_is_required(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageInsuranceCompanies::class)
            ->callAction('create', ['name' => ''])
            ->assertHasFormErrors(['name' => 'required']);

        Livewire::test(ManageInsuranceCompanies::class)
            ->callAction('create', ['name' => 'Seguradora SA', 'cnpj' => '12.345.678/0001-90'])
            ->assertHasNoFormErrors();

        $this->assertSame('Seguradora SA', CiaSeguro::sole()->name);

        $this->actingAs($this->user('booking', 'outro'));
        Livewire::test(ManageInsuranceCompanies::class)->assertForbidden();
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
