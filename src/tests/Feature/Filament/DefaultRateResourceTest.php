<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DefaultRates\Pages\ManageDefaultRates;
use App\Models\FinDefaultRates;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DefaultRateResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_default_rate(): void
    {
        $this->actingAs($this->user('financial'));
        $rate = DB::table('fin_currencies_rates')->insertGetId(['name' => 'PTAX', 'ref_currency' => 1]);

        Livewire::test(ManageDefaultRates::class)
            ->callAction('create', ['ref_currency_rate' => $rate, 'default_tax' => '3.25'])
            ->assertHasNoFormErrors();

        $this->assertEquals(3.25, FinDefaultRates::sole()->default_tax);
    }

    public function test_requires_admin_or_financial_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageDefaultRates::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
