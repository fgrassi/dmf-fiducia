<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CompanyExpenses\CompanyExpenseResource;
use App\Filament\Resources\CompanyExpenses\Pages\ManageCompanyExpenses;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\Resources\ExpenseCategories\Pages\ManageExpenseCategories;
use App\Filament\Resources\ExpenseDueDates\ExpenseDueDateResource;
use App\Filament\Resources\ExpenseDueDates\Pages\ManageExpenseDueDates;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Despesa;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CompanyExpenseResourceTest extends TestCase
{
    use LegacySchema;

    public function test_access_follows_the_legacy_index_rule(): void
    {
        foreach ([[], ['financial'], ['manager'], ['booking']] as $credentials) {
            $this->actingAs($this->user(...$credentials))->get(CompanyExpenseResource::getUrl())->assertForbidden();
        }

        foreach ([['director'], ['financial', 'manager'], ['admin']] as $credentials) {
            $this->actingAs($this->user(...$credentials))->get(CompanyExpenseResource::getUrl())->assertOk();
        }

        foreach ([SupplierResource::class, CostCenterResource::class, ExpenseCategoryResource::class, ExpenseDueDateResource::class] as $resource) {
            $this->actingAs($this->user('director'))->get($resource::getUrl())->assertOk();
            $this->actingAs($this->user('financial'))->get($resource::getUrl())->assertForbidden();
        }
    }

    public function test_user_2972_has_access_without_credentials(): void
    {
        $user = User::find(DB::table('usuario')->insertGetId(['usuario_id' => 2972, 'nome' => 'Sâmila', 'login' => 'samila', 'senha' => 'x', 'status' => 1, 'credentials' => '']));

        $this->actingAs($user)->get(CompanyExpenseResource::getUrl())->assertOk();
    }

    public function test_lists_only_open_expenses_by_default_and_filters_by_status_and_category(): void
    {
        $this->actingAs($this->user('director'));
        $open = $this->expense(['descricao' => 'ABERTA', 'categoria_despesa_id' => 1]);
        $closed = $this->expense(['descricao' => 'PAGA', 'status' => Despesa::STATUS_CLOSED, 'categoria_despesa_id' => 2]);

        Livewire::test(ManageCompanyExpenses::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed])
            ->assertSee(['Fornecedor Um', 'Aluguel', '1.234,50'])
            ->removeTableFilter('status')
            ->assertCanSeeTableRecords([$open, $closed])
            ->filterTable('categoria_despesa_id', [2])
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$open]);
    }

    public function test_search_filter_matches_description_and_date_range_without_running_text_as_sql(): void
    {
        $this->actingAs($this->user('director'));
        $march = $this->expense(['descricao' => 'Conta de luz', 'data_prevista' => '2026-03-10']);
        $june = $this->expense(['descricao' => 'Internet', 'data_prevista' => '2026-06-10']);

        Livewire::test(ManageCompanyExpenses::class)
            ->filterTable('busca', ['descricao' => 'luz'])
            ->assertCanSeeTableRecords([$march])
            ->assertCanNotSeeTableRecords([$june])
            ->filterTable('busca', ['prevista_de' => '2026-05-01'])
            ->assertCanSeeTableRecords([$june])
            ->assertCanNotSeeTableRecords([$march])
            ->filterTable('busca', ['descricao' => "' or 1=1 --"])
            ->assertCanNotSeeTableRecords([$march, $june]);
    }

    public function test_creates_an_open_expense_signed_by_the_user(): void
    {
        $user = $this->user('director');
        $this->actingAs($user);
        $this->references();

        Livewire::test(ManageCompanyExpenses::class)
            ->callAction('create', [
                'fornecedor_id' => 1,
                'centro_custo_id' => 1,
                'categoria_despesa_id' => 1,
                'valor' => 77.9,
                'data_prevista' => '2026-11-20',
                'tipo_despesa' => 2,
            ])
            ->assertHasNoFormErrors();

        $expense = Despesa::sole();
        $this->assertSame(Despesa::STATUS_OPEN, $expense->status);
        $this->assertSame($user->getKey(), $expense->created_by);
        $this->assertSame(2, $expense->tipo_despesa);
    }

    public function test_requires_supplier_cost_center_category_and_value(): void
    {
        $this->actingAs($this->user('director'));

        Livewire::test(ManageCompanyExpenses::class)
            ->callAction('create', [])
            ->assertHasFormErrors(['fornecedor_id' => 'required', 'centro_custo_id' => 'required', 'categoria_despesa_id' => 'required', 'valor' => 'required']);
    }

    public function test_payment_requires_type_and_date_and_closes_the_expense(): void
    {
        $this->actingAs($this->user('director'));
        $expense = $this->expense();

        Livewire::test(ManageCompanyExpenses::class)
            ->callAction(TestAction::make('pagar')->table($expense), ['data_efetiva' => null, 'tipo_pagamento' => null, 'valor' => 5])
            ->assertHasFormErrors(['data_efetiva' => 'required', 'tipo_pagamento' => 'required']);

        $this->assertSame(Despesa::STATUS_OPEN, $expense->fresh()->status);

        Livewire::test(ManageCompanyExpenses::class)
            ->callAction(TestAction::make('pagar')->table($expense), ['data_efetiva' => '2026-11-03', 'tipo_pagamento' => 6, 'valor' => 1234.5])
            ->assertHasNoFormErrors();

        $this->assertSame(Despesa::STATUS_CLOSED, $expense->fresh()->status);
    }

    public function test_duplicates_and_removes_from_the_table(): void
    {
        $this->actingAs($this->user('director'));
        $expense = $this->expense();

        Livewire::test(ManageCompanyExpenses::class)
            ->callAction(TestAction::make('duplicar')->table($expense));
        $this->assertSame(2, Despesa::count());

        Livewire::test(ManageCompanyExpenses::class)
            ->callAction(TestAction::make('remover')->table($expense));
        $this->assertSame(1, Despesa::count());
    }

    public function test_report_downloads_a_pdf_with_the_chosen_columns(): void
    {
        $this->actingAs($this->user('director'));
        $this->expense();

        Livewire::test(ManageCompanyExpenses::class)
            ->callAction('relatorio', ['columns' => ['supplier', 'value']])
            ->assertHasNoFormErrors()
            ->assertFileDownloaded('relatorio-despesas.pdf');
    }

    public function test_excel_downloads_only_what_the_list_filters_show(): void
    {
        $this->actingAs($this->user('director'));
        $this->expense();
        $this->expense(['status' => Despesa::STATUS_CLOSED]);

        Livewire::test(ManageCompanyExpenses::class)
            ->callAction('excel')
            ->assertFileDownloaded();
    }

    public function test_supplier_in_use_cannot_be_deleted_and_a_free_one_can(): void
    {
        $this->actingAs($this->user('director'));
        $this->expense();
        $free = DB::table('fornecedor')->insertGetId(['nome' => 'Livre']);

        Livewire::test(ManageSuppliers::class)
            ->assertTableActionHidden(DeleteAction::class, 1)
            ->callTableAction(DeleteAction::class, $free);

        $this->assertSame(1, DB::table('fornecedor')->count());
    }

    public function test_merge_bulk_action_unifies_the_selected_categories(): void
    {
        $this->actingAs($this->user('director'));
        $this->expense(['categoria_despesa_id' => 2]);

        Livewire::test(ManageExpenseCategories::class)
            ->selectTableRecords([1, 2])
            ->callAction(TestAction::make('unir')->table()->bulk(), ['destino' => 1])
            ->assertHasNoFormErrors()
            ->assertNotified('Categorias unidas');

        $this->assertSame([1], DB::table('categoria_despesa')->pluck('categoria_despesa_id')->all());
        $this->assertSame(1, Despesa::sole()->categoria_despesa_id);
    }

    public function test_merge_bulk_action_unifies_the_selected_suppliers(): void
    {
        $this->actingAs($this->user('director'));
        $this->expense();
        $other = DB::table('fornecedor')->insertGetId(['nome' => 'Duplicado']);
        $this->expense(['fornecedor_id' => $other]);

        Livewire::test(ManageSuppliers::class)
            ->selectTableRecords([1, $other])
            ->callAction(TestAction::make('unir')->table()->bulk(), ['destino' => 1])
            ->assertHasNoFormErrors()
            ->assertNotified('Fornecedores unidos');

        $this->assertSame([1], DB::table('fornecedor')->pluck('fornecedor_id')->all());
        $this->assertSame(2, Despesa::where('fornecedor_id', 1)->count());
    }

    public function test_due_date_requires_a_day_between_1_and_31_and_lists_by_day(): void
    {
        $this->actingAs($this->user('director'));
        $this->references();

        Livewire::test(ManageExpenseDueDates::class)
            ->callAction('create', ['fornecedor_id' => 1, 'categoria_despesa_id' => 1, 'dia_vencimento' => 32])
            ->assertHasFormErrors(['dia_vencimento']);

        Livewire::test(ManageExpenseDueDates::class)
            ->callAction('create', ['fornecedor_id' => 1, 'categoria_despesa_id' => 1, 'dia_vencimento' => 31, 'descricao' => 'Aluguel'])
            ->assertHasNoFormErrors();

        $this->assertSame(31, DB::table('despesa_vencimento')->value('dia_vencimento'));
    }

    private function references(): void
    {
        DB::table('fornecedor')->insertOrIgnore(['fornecedor_id' => 1, 'nome' => 'Fornecedor Um']);
        DB::table('centro_custo')->insertOrIgnore(['centro_custo_id' => 1, 'nome' => 'Adm']);
        DB::table('categoria_despesa')->insertOrIgnore([['categoria_despesa_id' => 1, 'nome' => 'Aluguel'], ['categoria_despesa_id' => 2, 'nome' => 'Luz']]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(array $attributes = []): Despesa
    {
        $this->references();

        return Despesa::create($attributes + [
            'fornecedor_id' => 1, 'centro_custo_id' => 1, 'categoria_despesa_id' => 1, 'valor' => 1234.5, 'data_prevista' => '2026-11-10',
            'tipo_despesa' => 1, 'status' => Despesa::STATUS_OPEN, 'created_by' => 1, 'created_at' => now(),
        ]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
