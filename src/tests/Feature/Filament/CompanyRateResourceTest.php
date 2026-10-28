<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CompanyRates\Pages\ManageCompanyRates;
use App\Models\FinCompaniesRates;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CompanyRateResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_negotiated_rate_and_lists_by_client_name(): void
    {
        $this->actingAs($this->user('financial'));
        $zeta = DB::table('pessoa')->insertGetId(['nome' => 'Zeta Ltda']);
        $alfa = DB::table('pessoa')->insertGetId(['nome' => 'Alfa Ltda']);
        $rate = DB::table('fin_currencies_rates')->insertGetId(['name' => 'PTAX', 'ref_currency' => 1]);

        Livewire::test(ManageCompanyRates::class)
            ->callAction('create', ['ref_pessoa' => $zeta, 'ref_currency_rate' => $rate, 'tax' => '1.5'])
            ->callAction('create', ['ref_pessoa' => $alfa, 'ref_currency_rate' => $rate, 'tax' => '2'])
            ->assertHasNoFormErrors();

        $ordered = FinCompaniesRates::orderBy('id')->get();
        Livewire::test(ManageCompanyRates::class)
            ->assertCanSeeTableRecords([$ordered[1], $ordered[0]], inOrder: true);
    }

    public function test_requires_financial_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageCompanyRates::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
