<?php

namespace Tests\Feature\Filament;

use App\Actions\PersonExpenses\SavePersonExpense;
use App\Filament\Resources\PersonExpenses\Pages\ManagePersonExpenses;
use App\Models\MatrizDespesaPessoa;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PersonExpenseResourceTest extends TestCase
{
    use LegacySchema;

    private int $person;

    private int $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-25');
        $this->person = DB::table('pessoa')->insertGetId(['nome' => 'ACME']);
        $this->expense = DB::table('fin_expenses')->insertGetId(['name' => 'THC']);
        DB::table('cmn_currencies')->insert(['id' => 1, 'name' => 'Real', 'symbol' => 'BRL']);
    }

    public function test_adding_an_expense_closes_the_open_ones_that_match_and_keeps_the_others(): void
    {
        $this->actingAs($user = $this->user('empresa'));
        $port = DB::table('porto')->insertGetId(['nome' => 'Santos']);
        $otherPort = DB::table('porto')->insertGetId(['nome' => 'Rio']);
        $old = $this->row(['porto_id' => $port]);
        $withoutPort = $this->row(['porto_id' => null]);
        $other = $this->row(['porto_id' => $otherPort]);
        $otherBilling = $this->row(['billing' => 'c']);
        $closed = $this->row(['due_to' => '2026-10-01']);

        $new = app(SavePersonExpense::class)($this->data(['porto_id' => $port, 'due_from' => '2026-12-01']), $user);

        $this->assertSame('2026-12-01', substr($old->fresh()->due_to, 0, 10));
        $this->assertNull($withoutPort->fresh()->due_to);
        $this->assertNull($other->fresh()->due_to);
        $this->assertNull($otherBilling->fresh()->due_to);
        $this->assertSame('2026-10-01', substr($closed->fresh()->due_to, 0, 10));
        $this->assertNull($new->fresh()->due_to);
        $this->assertSame($user->getKey(), (int) $new->created_by);
    }

    public function test_editing_keeps_who_created_it_and_the_person(): void
    {
        $this->actingAs($user = $this->user('empresa'));
        $row = $this->row(['created_by' => 99, 'created_at' => '2026-01-02']);
        $other = DB::table('pessoa')->insertGetId(['nome' => 'OUTRA']);

        app(SavePersonExpense::class)($this->data(['pessoa_id' => $other, 'expense_value' => 90]), $user, $row);

        $row = $row->fresh();
        $this->assertSame([99, $this->person, 90.0], [(int) $row->created_by, (int) $row->pessoa_id, (float) $row->expense_value]);
        $this->assertSame($user->getKey(), (int) $row->updated_by);
        $this->assertSame('2026-11-25', substr($row->updated_at, 0, 10));
    }

    public function test_the_report_lists_only_current_rows_filtered_by_client_expense_and_type(): void
    {
        $this->actingAs($this->user('empresa'));
        $current = $this->row(['impor_expor' => 'i']);
        $future = $this->row(['due_from' => '2026-12-01']);
        $expired = $this->row(['due_to' => '2026-11-24']);
        $export = $this->row(['impor_expor' => 'e', 'ref_expense' => DB::table('fin_expenses')->insertGetId(['name' => 'BL Fee'])]);
        $otherClient = $this->row(['pessoa_id' => DB::table('pessoa')->insertGetId(['nome' => 'ZETA'])]);

        $this->page()
            ->assertCanSeeTableRecords([$current, $export, $otherClient])
            ->assertCanNotSeeTableRecords([$future, $expired])
            ->removeTableFilter('only_current')
            ->assertCanSeeTableRecords([$current, $future, $expired])
            ->filterTable('client', ['client' => 'ZET'])
            ->assertCanSeeTableRecords([$otherClient])
            ->assertCanNotSeeTableRecords([$current, $export])
            ->resetTableFilters()
            ->filterTable('expense', ['expense' => 'BL'])
            ->assertCanSeeTableRecords([$export])
            ->assertCanNotSeeTableRecords([$current])
            ->resetTableFilters()
            ->filterTable('impor_expor', ['e'])
            ->assertCanSeeTableRecords([$export])
            ->assertCanNotSeeTableRecords([$current]);
    }

    public function test_the_value_is_edited_in_the_row_by_users_with_the_company_credential_only(): void
    {
        $row = $this->row(['expense_value' => 100]);

        $this->actingAs($this->user('empresa'));
        $this->page()->call('updateTableColumnState', 'expense_value', $row->getKey(), '123.45');
        $row = $row->fresh();
        $this->assertSame(123.45, (float) $row->expense_value);
        $this->assertNotNull($row->updated_by);

        $this->actingAs($this->user('booking', 'outra'));
        $this->page()
            ->assertCanSeeTableRecords([$row])
            ->call('updateTableColumnState', 'expense_value', $row->getKey(), '999');
        $this->assertSame(123.45, (float) $row->fresh()->expense_value);
    }

    public function test_the_header_action_adds_and_the_row_action_edits_and_deletes(): void
    {
        $this->actingAs($this->user('empresa'));
        $old = $this->row();

        $this->page()
            ->callAction('create', $this->data(['expense_value' => 150, 'due_from' => '2026-11-25']))
            ->assertHasNoFormErrors();

        $new = MatrizDespesaPessoa::where('expense_value', 150)->firstOrFail();
        $this->assertSame('2026-11-25', substr($old->fresh()->due_to, 0, 10));

        $this->page()
            ->callAction(TestAction::make('edit')->table($new), ['expense_value' => 175])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('delete')->table($new))
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('matriz_despesa_pessoa', ['id' => $new->getKey()]);
    }

    private function page()
    {
        return Livewire::test(ManagePersonExpenses::class);
    }

    /** @param  array<string, mixed>  $attributes */
    private function data(array $attributes = []): array
    {
        return $attributes + ['pessoa_id' => $this->person, 'ref_expense' => $this->expense, 'ref_currency' => 1, 'billing' => 'p', 'impor_expor' => 'i', 'expense_value' => 100, 'due_from' => '2026-11-01'];
    }

    /** @param  array<string, mixed>  $attributes */
    private function row(array $attributes = []): MatrizDespesaPessoa
    {
        return MatrizDespesaPessoa::create($attributes + $this->data() + ['created_at' => '2026-01-01', 'created_by' => 1]);
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
