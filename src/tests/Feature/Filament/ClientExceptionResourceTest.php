<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClientExceptions\Pages\ManageClientExceptions;
use App\Models\FinClientsException;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ClientExceptionResourceTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente A', 'cnpj' => '123']);
    }

    public function test_adds_client_with_author(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        Livewire::test(ManageClientExceptions::class)
            ->callAction('create', ['ref_client' => 5, 'justificativa' => 'Recalcula antes'])
            ->assertHasNoFormErrors();

        $row = FinClientsException::sole();
        $this->assertSame([5, 'Recalcula antes', $user->usuario_id], [(int) $row->ref_client, $row->justificativa, (int) $row->created_by]);
        $this->assertNotNull($row->created_at);
    }

    public function test_rejects_missing_or_repeated_client(): void
    {
        $this->actingAs($this->user());
        FinClientsException::forceCreate(['ref_client' => 5]);

        Livewire::test(ManageClientExceptions::class)
            ->callAction('create', ['ref_client' => null])
            ->assertHasFormErrors(['ref_client' => 'required']);

        Livewire::test(ManageClientExceptions::class)
            ->callAction('create', ['ref_client' => 5])
            ->assertHasFormErrors(['ref_client' => 'unique']);
    }

    public function test_removes_client(): void
    {
        $this->actingAs($this->user());
        $row = FinClientsException::forceCreate(['ref_client' => 5]);

        Livewire::test(ManageClientExceptions::class)
            ->assertCanSeeTableRecords([$row])
            ->callTableAction(DeleteAction::class, $row);

        $this->assertNull($row->fresh());
    }

    public function test_requires_financial_credential(): void
    {
        $this->actingAs($this->user(credentials: ''));

        Livewire::test(ManageClientExceptions::class)->assertForbidden();
    }

    private function user(string $credentials = 'financial'): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);

        return User::find($id);
    }
}
