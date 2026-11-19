<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TransitTimes\Pages\ManageTransitTimes;
use App\Models\TransitTime;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

/** Transit Time (transitTime do legado). */
class TransitTimeResourceTest extends TestCase
{
    use LegacySchema;

    public function test_transit_time_is_created(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $person = DB::table('pessoa')->insertGetId(['nome' => 'Cliente']);
        $carrier = DB::table('armador')->insertGetId(['nome' => 'MSC']);
        $origin = DB::table('porto')->insertGetId(['nome' => 'Santos']);
        $destination = DB::table('porto')->insertGetId(['nome' => 'Roterdã']);

        Livewire::test(ManageTransitTimes::class)
            ->callAction('create', ['pessoa_id' => $person, 'armador_id' => $carrier, 'porto_origem_id' => $origin, 'porto_destino_id' => $destination, 'transit_time' => 18, 'transit_time_seguranca' => 21])
            ->assertHasNoFormErrors();

        $this->assertSame(21, (int) TransitTime::sole()->transit_time_seguranca);
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
