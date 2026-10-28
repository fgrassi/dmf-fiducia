<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Models\CmnCurrency;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CurrencyResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_unused_record(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageCurrencies::class)
            ->callAction('create', ['name' => 'Dólar', 'symbol' => 'USD'])
            ->assertHasNoFormErrors();

        $record = CmnCurrency::sole();
        $this->assertSame('USD', $record->symbol);

        Livewire::test(ManageCurrencies::class)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertNull($record->fresh());
    }

    public function test_referenced_record_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('financial'));
        $record = CmnCurrency::create(['name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses')->insert(['name' => 'THC', 'ref_currency' => $record->id]);

        Livewire::test(ManageCurrencies::class)
            ->assertTableActionHidden(DeleteAction::class, $record);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageCurrencies::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
