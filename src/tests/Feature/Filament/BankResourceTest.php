<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Banks\Pages\ManageBanks;
use App\Models\CmnBanks;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BankResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_unused_record(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageBanks::class)
            ->callAction('create', ['name' => 'Itaú', 'bank_number' => '341'])
            ->assertHasNoFormErrors();

        $record = CmnBanks::sole();
        $this->assertSame('341', $record->bank_number);
        $this->assertNotNull($record->created_by);

        Livewire::test(ManageBanks::class)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertNull($record->fresh());
    }

    public function test_referenced_record_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('financial'));
        $record = CmnBanks::create(['name' => 'Itaú', 'bank_number' => '341', 'created_by' => 1]);
        DB::table('fin_armador_banks')->insert(['ref_armador' => 1, 'ref_bank' => $record->id, 'created_by' => 1]);

        Livewire::test(ManageBanks::class)
            ->assertTableActionHidden(DeleteAction::class, $record);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageBanks::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
