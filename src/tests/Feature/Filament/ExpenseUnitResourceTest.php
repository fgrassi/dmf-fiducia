<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ExpenseUnits\Pages\ManageExpenseUnits;
use App\Models\FinExpensesUnity;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseUnitResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_unused_record(): void
    {
        $this->actingAs($this->user('admin'));

        Livewire::test(ManageExpenseUnits::class)
            ->callAction('create', ['name' => 'Container', 'acronym' => 'CNTR'])
            ->assertHasNoFormErrors();

        $record = FinExpensesUnity::sole();
        $this->assertSame('CNTR', $record->acronym);

        Livewire::test(ManageExpenseUnits::class)
            ->callTableAction(DeleteAction::class, $record);

        $this->assertNull($record->fresh());
    }

    public function test_referenced_record_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $record = FinExpensesUnity::create(['name' => 'Container', 'acronym' => 'CNTR']);
        DB::table('fin_expenses')->insert(['name' => 'THC', 'ref_unity' => $record->id]);

        Livewire::test(ManageExpenseUnits::class)
            ->assertTableActionHidden(DeleteAction::class, $record);
    }

    public function test_requires_credential(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageExpenseUnits::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
