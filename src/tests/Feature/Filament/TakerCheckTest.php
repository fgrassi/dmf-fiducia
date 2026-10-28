<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TakerCheck;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class TakerCheckTest extends TestCase
{
    use LegacySchema;

    public function test_searches_period_and_lists_pending_takers(): void
    {
        $this->actingAs($this->user('it'));
        $this->booking();

        Livewire::test(TakerCheck::class)
            ->set('data.data_inicio', '2026-11-01')
            ->set('data.data_fim', '2026-11-05')
            ->call('search')
            ->assertHasNoFormErrors()
            ->assertSet('data.rows.0.pendencias', ['Empresa sem cadastro de pessoa.']);
    }

    public function test_update_action_saves_edited_rows_and_creates_the_person(): void
    {
        $this->actingAs($this->user('it'));
        $this->booking();

        Livewire::test(TakerCheck::class)
            ->set('data.data_inicio', '2026-11-01')
            ->set('data.data_fim', '2026-11-05')
            ->call('search')
            ->set('data.rows.0.nome', 'acme ltda')
            ->call('updateRegistry');

        $this->assertSame('ACME LTDA', DB::table('pessoa')->sole()->nome);
    }

    public function test_requires_it_credential(): void
    {
        $this->actingAs($this->user(credentials: 'financial'));

        Livewire::test(TakerCheck::class)->assertForbidden();
    }

    private function booking(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.opencnpj.org/*' => Http::response([], 404)]);
        $id = DB::table('embarque')->insertGetId(['impor_expor' => 'e', 'saida_prevista' => '2026-11-03', 'finalizado' => 'a']);
        DB::table('bl')->insert(['embarque_id' => $id, 'shipper' => 'CNPJ 11.222.333/0001-81']);
    }

    private function user(string $credentials = 'it'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
