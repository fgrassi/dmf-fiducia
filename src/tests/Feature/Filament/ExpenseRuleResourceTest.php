<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ExpenseRules\Pages\ManageExpenseRules;
use App\Models\RegraDespesaCobranca;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseRuleResourceTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'Container', 'acronym' => 'CTR']);
        DB::table('fin_expenses')->insert(['id' => 10, 'name' => 'Frete', 'ref_unity' => 1]);
        DB::table('fin_expenses')->insert(['id' => 11, 'name' => 'THC']);
        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'financial'])));
    }

    public function test_creates_rule_with_expenses(): void
    {
        Livewire::test(ManageExpenseRules::class)
            ->callAction('create', ['nome' => 'Frete EXPO', 'tipo_embarque' => 'm', 'impor_expor' => 'e', 'obrigatoria' => false, 'expenses' => [10, 11]])
            ->assertHasNoFormErrors();

        $rule = RegraDespesaCobranca::sole();
        $this->assertSame(['Frete / CTR', 'THC'], $rule->expenses->map->label->sort()->values()->all());
        $this->assertFalse($rule->obrigatoria);
    }

    public function test_requires_name_type_and_direction(): void
    {
        Livewire::test(ManageExpenseRules::class)
            ->callAction('create', ['nome' => null, 'tipo_embarque' => null, 'impor_expor' => null])
            ->assertHasFormErrors(['nome' => 'required', 'tipo_embarque' => 'required', 'impor_expor' => 'required']);
    }

    public function test_delete_removes_items_and_nfse_links(): void
    {
        $rule = RegraDespesaCobranca::create(['nome' => 'Grupo', 'tipo_embarque' => 'm', 'impor_expor' => 'i']);
        $rule->expenses()->attach(10);
        DB::table('fin_clients_nfse_regra_despesa')->insert(['ref_nfse_regra' => 1, 'regra_despesa_cobranca_id' => $rule->getKey()]);

        Livewire::test(ManageExpenseRules::class)
            ->callTableAction(DeleteAction::class, $rule);

        $this->assertNull($rule->fresh());
        $this->assertSame(0, DB::table('regra_despesa_cobranca_item')->count());
        $this->assertSame(0, DB::table('fin_clients_nfse_regra_despesa')->count());
    }

    public function test_requires_financial_credential(): void
    {
        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Bia', 'login' => 'bia', 'senha' => 'x', 'status' => 1, 'credentials' => ''])));

        Livewire::test(ManageExpenseRules::class)->assertForbidden();
    }
}
