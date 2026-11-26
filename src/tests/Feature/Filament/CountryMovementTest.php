<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CountryMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CountryMovementTest extends TestCase
{
    use LegacySchema;

    public function test_country_movement_asks_for_the_period_and_shows_the_countries_with_the_total(): void
    {
        $this->actingAs($this->user('director'));
        DB::table('paises')->insert(['paises_id' => 2, 'nome' => 'China', 'uncode' => 'CN']);
        DB::table('porto')->insert([['porto_id' => 10, 'nome' => 'Santos', 'ref_pais' => 1], ['porto_id' => 20, 'nome' => 'Shanghai', 'ref_pais' => 2]]);
        $id = DB::table('embarque')->insertGetId(['booking' => 'B1', 'impor_expor' => 'i', 'porto_origem_id' => 20, 'porto_destino_id' => 10, 'saida_prevista' => '2026-11-05', 'finalizado' => 'n']);
        DB::table('mercadoria')->insert(['embarque_id' => $id, 'quantidade' => 3]);

        Livewire::test(CountryMovement::class)
            ->assertSee('Informe a Data Inicial e a Data Final para gerar o relatório.')
            ->filterTable('params', ['data_ini' => '2026-12-01', 'data_fim' => '2026-11-01'])
            ->assertSee('A Data Inicial não pode ser maior que a Data Final.')
            ->filterTable('params', ['data_ini' => '2026-11-01', 'data_fim' => '2026-11-30'])
            ->assertSee(['China (CN)', '100,00%', 'Total: 1 embarques e 3 containers'])
            ->filterTable('params', ['data_ini' => '2026-11-01', 'data_fim' => '2026-11-30', 'impor_expor' => 'e'])
            ->assertSee('Nenhum embarque encontrado para os filtros informados.');
    }

    public function test_the_page_needs_the_legacy_credential(): void
    {
        $this->actingAs($this->user('booking'));

        $this->get(CountryMovement::getUrl())->assertForbidden();

        $this->actingAs($this->user('director', 'outra'));
        $this->get(CountryMovement::getUrl())->assertOk();
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
