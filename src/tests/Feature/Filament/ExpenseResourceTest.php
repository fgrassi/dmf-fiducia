<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Models\FinExpenses;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_unused_record(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageExpenses::class)
            ->callAction('create', ['name' => 'THC', 'operation_type' => 'i', 'min_value' => 10.5])
            ->assertHasNoFormErrors();

        $record = FinExpenses::sole();
        $this->assertSame('i', $record->operation_type);

        Livewire::test(ManageExpenses::class)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertNull($record->fresh());
    }

    public function test_referenced_record_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('booking'));
        $record = FinExpenses::create(['name' => 'THC', 'operation_type' => 'i', 'min_value' => 10.5]);
        DB::table('fin_charges_items')->insert(['ref_expense' => $record->id, 'ref_charge' => 1, 'ref_currency' => 1, 'created_at' => now()]);

        Livewire::test(ManageExpenses::class)
            ->assertTableActionHidden(DeleteAction::class, $record);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageExpenses::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
