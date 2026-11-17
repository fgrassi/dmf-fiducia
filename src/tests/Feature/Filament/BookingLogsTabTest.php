<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\LogsRelationManager;
use App\Models\Embarque;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingLogsTabTest extends TestCase
{
    use LegacySchema;

    public function test_lists_only_this_bookings_log_and_opens_its_history(): void
    {
        $this->actingAs($this->user('booking'));
        $booking = $this->booking();
        $other = $this->booking();
        $mine = DB::table('rastreamento')->insertGetId(['assunto' => 'ETD alterado', 'historico' => '&lt;b&gt;de A para B&lt;/b&gt;', 'ref_embarque' => $booking->getKey(), 'created_at' => '2026-01-02', 'created_time' => '10:00:00', 'created_by' => 1]);
        $theirs = DB::table('rastreamento')->insertGetId(['assunto' => 'Outro', 'ref_embarque' => $other->getKey(), 'created_at' => '2026-01-02', 'created_by' => 1]);

        Livewire::test(LogsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertSee('ETD alterado')
            ->assertSee('02/01/2026 10:00:00');
    }

    private function booking(): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId(['booking' => 'EXP'.random_int(1000, 9999), 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'pessoa_id_representante' => 55]));
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.random_int(1, 99999), 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
