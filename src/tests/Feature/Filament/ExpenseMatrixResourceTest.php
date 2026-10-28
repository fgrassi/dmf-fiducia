<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ExpenseMatrix\Pages\ManageExpenseMatrix;
use App\Models\CmnArmadorPorto;
use App\Models\FinExpensesMatrix;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseMatrixResourceTest extends TestCase
{
    use LegacySchema;

    private CmnArmadorPorto $port;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-28');
        $this->port = CmnArmadorPorto::create([
            'ref_armador' => DB::table('armador')->insertGetId(['nome' => 'Hapag']),
            'ref_porto' => DB::table('porto')->insertGetId(['nome' => 'Santos']),
        ]);
    }

    public function test_adds_expense_per_equipment_from_the_header_action(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $expense = DB::table('fin_expenses')->insertGetId(['name' => 'THC']);
        $currency = DB::table('cmn_currencies')->insertGetId(['name' => 'Dólar', 'symbol' => 'USD']);
        $containers = [DB::table('tipo_container')->insertGetId(['nome' => "20'"]), DB::table('tipo_container')->insertGetId(['nome' => "40'"])];

        $this->page()
            ->callAction('create', [
                'type' => 'i', 'ref_expense' => $expense, 'ref_equipamento' => $containers, 'ref_currency' => $currency,
                'billing' => 'p', 'expense_value' => '250', 'due_from' => '2026-11-01',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(2, FinExpensesMatrix::where('ref_armador_porto', $this->port->id)->count());
    }

    public function test_lists_only_active_expenses_unless_filter_is_cleared(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $active = $this->row(['due_to' => null]);
        $future = $this->row(['due_to' => '2026-10-28']);
        $expired = $this->row(['due_to' => '2026-10-27']);

        $this->page()
            ->assertCanSeeTableRecords([$active, $future])
            ->assertCanNotSeeTableRecords([$expired])
            ->removeTableFilter('only_active')
            ->assertCanSeeTableRecords([$active, $future, $expired]);
    }

    public function test_user_with_only_export_credential_sees_export_and_both_types(): void
    {
        $this->actingAs($this->user('auxiliares,export'));
        $export = $this->row(['type' => 'e']);
        $both = $this->row(['type' => 'b']);
        $import = $this->row(['type' => 'i']);

        $this->page()
            ->assertCanSeeTableRecords([$export, $both])
            ->assertCanNotSeeTableRecords([$import])
            ->removeTableFilter('only_active')
            ->assertCanNotSeeTableRecords([$import]);
    }

    public function test_audit_is_skipped_only_when_just_minimums_change(): void
    {
        $user = $this->user('auxiliares');
        $this->actingAs($user);
        $row = $this->row();
        DB::table('fin_expenses')->where('id', $row->ref_expense)->update(['ref_unity' => 3]);
        $currency = DB::table('cmn_currencies')->insertGetId(['name' => 'Dólar', 'symbol' => 'USD']);
        $row->update(['ref_currency' => $currency]);

        $this->page()
            ->callTableAction(EditAction::class, $row, ['compra_minimo' => '10', 'type' => 'i', 'ref_currency' => $currency, 'billing' => 'p', 'expense_value' => $row->expense_value, 'due_from' => $row->due_from])
            ->assertHasNoTableActionErrors();
        $this->assertNull($row->fresh()->updated_by);

        $this->page()
            ->callTableAction(EditAction::class, $row, ['expense_value' => '99', 'type' => 'i', 'ref_currency' => $currency, 'billing' => 'p', 'due_from' => $row->due_from])
            ->assertHasNoTableActionErrors();
        $this->assertSame($user->getKey(), (int) $row->fresh()->updated_by);
    }

    public function test_deletes_expense_and_requires_credential(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $row = $this->row();

        $this->page()->callTableAction(DeleteAction::class, $row);
        $this->assertNull($row->fresh());

        $this->actingAs($this->user('booking', 'outra'));
        $this->page()->assertForbidden();
    }

    private function page()
    {
        return Livewire::test(ManageExpenseMatrix::class, ['armadorPorto' => $this->port->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function row(array $attributes = []): FinExpensesMatrix
    {
        return FinExpensesMatrix::create($attributes + [
            'ref_armador_porto' => $this->port->id,
            'ref_expense' => DB::table('fin_expenses')->insertGetId(['name' => 'THC']),
            'type' => 'i',
            'expense_value' => 100,
            'imo_value' => 0,
            'ref_currency' => 1,
            'billing' => 'p',
            'due_from' => '2026-01-01',
            'created_by' => 1,
        ]);
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
