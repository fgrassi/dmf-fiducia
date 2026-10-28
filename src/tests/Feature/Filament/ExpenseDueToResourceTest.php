<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ExpenseDueTos\Pages\ManageExpenseDueTos;
use App\Models\FinExpensesValuesDueTo;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseDueToResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_due_to(): void
    {
        $this->actingAs($this->user('admin'));

        Livewire::test(ManageExpenseDueTos::class)
            ->callAction('create', ['name' => 'Armador X'])
            ->assertHasNoFormErrors();

        $row = FinExpensesValuesDueTo::sole();

        Livewire::test(ManageExpenseDueTos::class)
            ->callTableAction(DeleteAction::class, $row);

        $this->assertNull($row->fresh());
    }

    public function test_allows_it_credential(): void
    {
        $this->actingAs($this->user('it'));

        Livewire::test(ManageExpenseDueTos::class)->assertSuccessful();
    }

    public function test_denies_other_credentials(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageExpenseDueTos::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
