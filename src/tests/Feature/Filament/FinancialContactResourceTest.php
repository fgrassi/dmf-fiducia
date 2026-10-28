<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FinancialContacts\Pages\ManageFinancialContacts;
use App\Models\FinContatoCobranca;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class FinancialContactResourceTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente A', 'cnpj' => '123']);
    }

    public function test_adds_contact_with_author(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        Livewire::test(ManageFinancialContacts::class)
            ->callAction('create', ['ref_pessoa' => 5, 'email' => 'fin@cliente.com', 'descr' => 'Sempre copiar'])
            ->assertHasNoFormErrors();

        $row = FinContatoCobranca::sole();
        $this->assertSame([5, 'fin@cliente.com', $user->usuario_id], [(int) $row->ref_pessoa, $row->email, (int) $row->created_by]);
        $this->assertNotNull($row->created_at);
    }

    public function test_requires_client_valid_email_and_description(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ManageFinancialContacts::class)
            ->callAction('create', ['ref_pessoa' => null, 'email' => 'nao-e-email', 'descr' => null])
            ->assertHasFormErrors(['ref_pessoa' => 'required', 'email' => 'email', 'descr' => 'required']);
    }

    public function test_removes_contact(): void
    {
        $this->actingAs($this->user());
        $row = FinContatoCobranca::forceCreate(['ref_pessoa' => 5, 'email' => 'a@b.com', 'created_by' => 1, 'created_at' => now(), 'descr' => 'x']);

        Livewire::test(ManageFinancialContacts::class)
            ->assertCanSeeTableRecords([$row])
            ->callTableAction(DeleteAction::class, $row);

        $this->assertNull($row->fresh());
    }

    public function test_requires_financial_credential(): void
    {
        $this->actingAs($this->user(credentials: ''));

        Livewire::test(ManageFinancialContacts::class)->assertForbidden();
    }

    private function user(string $credentials = 'financial'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
