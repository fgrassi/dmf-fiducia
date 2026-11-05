<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\FiduciaClientBookings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class FiduciaClientBookingsTest extends TestCase
{
    use LegacySchema;

    public function test_only_director_or_manager_with_financial_open_the_page(): void
    {
        $this->actingAs($this->user('financial'))->get(FiduciaClientBookings::getUrl())->assertForbidden();
        $this->actingAs($this->user('manager', 'financial'))->get(FiduciaClientBookings::getUrl())->assertOk();
        $this->actingAs($this->user('director'))->get(FiduciaClientBookings::getUrl())->assertOk();
    }

    public function test_sends_the_report_with_the_booking_list_and_warns_about_an_invalid_period(): void
    {
        $this->actingAs($this->user('director'));
        DB::table('pessoa')->insert(['pessoa_id' => 7, 'nome' => 'Cliente Sete']);
        DB::table('fiducia_desconto')->insert(['pessoa_id' => 7, 'moeda_id' => 1, 'valor' => 10, 'tipo_desconto' => 'c']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG-10', 'cliente_id' => 7, 'saida_efetiva' => now()->startOfMonth()->toDateString(), 'finalizado' => 'a']);

        Livewire::test(FiduciaClientBookings::class)
            ->fillForm(['listar_embarques' => true])
            ->call('send')
            ->assertSee(['Cliente Sete', 'BKG-10', 'Confirmado', 'Total'])
            ->fillForm(['mes_ini' => 12, 'mes_fim' => 1])
            ->call('send')
            ->assertNotified('O período início deve ser anterior ou igual ao período fim.');
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
