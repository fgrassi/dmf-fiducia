<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\SpecialInstructions;
use App\Models\Embarque;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SpecialInstructionsTest extends TestCase
{
    use LegacySchema;

    public function test_special_instructions_list_only_open_export_bookings_without_departure(): void
    {
        $this->actingAs($this->user('followup'));
        $listed = $this->booking('e', 'n');
        $this->booking('e', 'c');
        $this->booking('i', 'n');
        $confirmed = $this->booking('e', 'n');
        $withoutInstruction = Embarque::find(DB::table('embarque')->insertGetId(['booking' => 'SEM', 'impor_expor' => 'e', 'finalizado' => 'n']));
        DB::table('departure_confirmation')->insert(['ref_embarque' => $confirmed->getKey(), 'created_by' => 1]);
        DB::table('instrucao_especial_embarque')->insert(['embarque_id' => $listed->getKey(), 'instrucao' => 'Segunda', 'created_at' => '2026-11-02', 'created_by' => 1]);

        Livewire::test(SpecialInstructions::class)
            ->assertCanSeeTableRecords([$listed])
            ->assertCanNotSeeTableRecords([$confirmed, $withoutInstruction])
            ->assertCountTableRecords(1)
            ->assertSee('Primeira');
    }

    public function test_the_page_needs_the_legacy_credential(): void
    {
        $this->actingAs($this->user('booking'));

        $this->get(SpecialInstructions::getUrl())->assertForbidden();

        $this->actingAs($this->user('followup', 'outra'));
        $this->get(SpecialInstructions::getUrl())->assertOk();
    }

    private function booking(string $type, string $status): Embarque
    {
        $booking = Embarque::find(DB::table('embarque')->insertGetId(['booking' => 'BK'.random_int(1000, 9999), 'impor_expor' => $type, 'finalizado' => $status, 'saida_prevista' => '2026-11-20']));
        DB::table('instrucao_especial_embarque')->insert(['embarque_id' => $booking->getKey(), 'instrucao' => 'Primeira', 'created_at' => '2026-11-01', 'created_by' => 1]);

        return $booking;
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
