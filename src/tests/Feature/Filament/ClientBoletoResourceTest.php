<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClientBoletos\Pages\ManageClientBoletos;
use App\Models\FinClientsBoleto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ClientBoletoResourceTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente A', 'cnpj' => '123']);
    }

    public function test_creates_negotiation_for_client(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ManageClientBoletos::class)
            ->callAction('create', ['ref_client' => 5, 'prazo' => '30 dias', 'prazo_imp' => 30, 'prazo_exp' => 0, 'boleto_imp' => 1, 'boleto_exp' => 0])
            ->assertHasNoFormErrors();

        $row = FinClientsBoleto::sole();
        $this->assertSame([5, 30, 1], [(int) $row->ref_client, (int) $row->prazo_imp, (int) $row->boleto_imp]);
    }

    public function test_requires_client(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ManageClientBoletos::class)
            ->callAction('create', ['ref_client' => null])
            ->assertHasFormErrors(['ref_client' => 'required']);
    }

    public function test_removal_requires_justification_and_notifies_finance(): void
    {
        $this->actingAs($this->user());
        $row = FinClientsBoleto::forceCreate(['ref_client' => 5, 'prazo_imp' => 30]);

        Livewire::test(ManageClientBoletos::class)
            ->assertCanSeeTableRecords([$row])
            ->callTableAction('remover', $row, ['justificativa' => ''])
            ->assertHasTableActionErrors(['justificativa' => 'required']);

        Livewire::test(ManageClientBoletos::class)
            ->callTableAction('remover', $row, ['justificativa' => 'Cliente encerrou o prazo']);

        $this->assertNull($row->fresh());
        $this->assertSame(['Remoção de Prazo Negociado de Cliente: Cliente A'], $this->sentSubjects()->all());
    }

    private function user(): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'financial']);

        return User::find($id);
    }
}
