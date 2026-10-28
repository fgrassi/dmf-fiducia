<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ExpenseGroups\Pages\CreateExpenseGroup;
use App\Filament\Resources\ExpenseGroups\Pages\EditExpenseGroup;
use App\Filament\Resources\ExpenseGroups\Pages\ListExpenseGroups;
use App\Filament\Resources\ExpenseGroups\RelationManagers\ExpensesRelationManager;
use App\Models\GrupoDespesa;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseGroupResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_group_and_adds_required_expense(): void
    {
        $this->actingAs($this->user());

        Livewire::test(CreateExpenseGroup::class)
            ->fillForm(['nome' => 'Documentação', 'cc' => 'd'])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = GrupoDespesa::sole();
        $expense = DB::table('fin_expenses')->insertGetId(['name' => 'BL Fee']);

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditExpenseGroup::class])
            ->callAction(TestAction::make('create')->table(), ['despesa_id' => $expense])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $group->expenses()->where('despesa_id', $expense)->count());
    }

    public function test_requires_admin_or_financial_credential(): void
    {
        $this->actingAs($this->user('booking'));
        $group = GrupoDespesa::create(['nome' => 'Documentação', 'cc' => 'd']);

        Livewire::test(ListExpenseGroups::class)->assertForbidden();
        Livewire::test(CreateExpenseGroup::class)->assertForbidden();
        Livewire::test(EditExpenseGroup::class, ['record' => $group->getKey()])->assertForbidden();
    }

    private function user(string $credentials = 'financial'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
