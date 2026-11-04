<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FiduciaDescontos\Pages\ManageFiduciaDescontos;
use App\Models\FiduciaDesconto;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class FiduciaDescontoResourceTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente A', 'cnpj' => '123']);
        DB::table('cmn_currencies')->insert(['id' => 1, 'name' => 'Real', 'symbol' => 'R$']);
    }

    public function test_adds_discount(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ManageFiduciaDescontos::class)
            ->callAction('create', ['pessoa_id' => 5, 'tipo_desconto' => 'c', 'moeda_id' => 1, 'valor' => '12.5'])
            ->assertHasNoFormErrors();

        $row = FiduciaDesconto::sole();
        $this->assertSame([5, 'c', 1, 12.5], [(int) $row->pessoa_id, $row->tipo_desconto, (int) $row->moeda_id, (float) $row->valor]);
    }

    public function test_requires_all_fields_and_valid_type(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ManageFiduciaDescontos::class)
            ->callAction('create', ['pessoa_id' => null, 'tipo_desconto' => 'x', 'moeda_id' => null, 'valor' => null])
            ->assertHasFormErrors(['pessoa_id' => 'required', 'tipo_desconto' => 'in', 'moeda_id' => 'required', 'valor' => 'required']);

        $this->assertSame(0, FiduciaDesconto::count());
    }

    public function test_removes_discount(): void
    {
        $this->actingAs($this->user());
        $row = FiduciaDesconto::forceCreate(['pessoa_id' => 5, 'tipo_desconto' => 'p', 'moeda_id' => 1, 'valor' => 10]);

        Livewire::test(ManageFiduciaDescontos::class)
            ->assertCanSeeTableRecords([$row])
            ->callTableAction(DeleteAction::class, $row);

        $this->assertNull($row->fresh());
    }

    public function test_requires_financial_credential(): void
    {
        $this->actingAs($this->user(credentials: ''));

        Livewire::test(ManageFiduciaDescontos::class)->assertForbidden();
    }

    private function user(string $credentials = 'financial'): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);

        return User::find($id);
    }
}
