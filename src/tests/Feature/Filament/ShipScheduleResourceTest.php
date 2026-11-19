<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ShipSchedules\Pages\ManageShipSchedules;
use App\Models\ProgramacaoNavio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

/** Programação de Navios (programacaoNavio do legado). */
class ShipScheduleResourceTest extends TestCase
{
    use LegacySchema;

    public function test_ship_schedule_counts_the_transit_days_and_requires_arrival_after_departure(): void
    {
        $this->actingAs($user = $this->user('auxiliares'));
        $ship = DB::table('navio')->insertGetId(['nome' => 'MSC Ana']);
        $origin = DB::table('porto')->insertGetId(['nome' => 'Santos']);
        $destination = DB::table('porto')->insertGetId(['nome' => 'Roterdã']);

        $form = ['joint' => 'MSC/Maersk', 'navio_id' => $ship, 'vg' => '123E', 'via' => 'Direto', 'porto_origem_id' => $origin, 'porto_destino_id' => $destination];

        Livewire::test(ManageShipSchedules::class)
            ->callAction('create', [...$form, 'saida_prevista' => '2026-12-10', 'chegada_prevista' => '2026-12-01'])
            ->assertHasFormErrors(['chegada_prevista']);

        Livewire::test(ManageShipSchedules::class)
            ->callAction('create', [...$form, 'saida_prevista' => '2026-12-01', 'chegada_prevista' => '2026-12-19'])
            ->assertHasNoFormErrors();

        $schedule = ProgramacaoNavio::sole();
        $this->assertSame(18, $schedule->transitDays());
        $this->assertSame($user->getKey(), (int) $schedule->created_by);
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
