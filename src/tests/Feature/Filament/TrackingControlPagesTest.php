<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ExportTrackingControl;
use App\Filament\Pages\ImportTrackingControl;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class TrackingControlPagesTest extends TestCase
{
    use LegacySchema;

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }

    public function test_pages_need_the_booking_credential(): void
    {
        $this->actingAs($this->user('financial'));
        $this->get(ImportTrackingControl::getUrl())->assertForbidden();
        $this->get(ExportTrackingControl::getUrl())->assertForbidden();

        $this->actingAs($this->user('booking'));
        $this->get(ImportTrackingControl::getUrl())->assertOk();
        $this->get(ExportTrackingControl::getUrl())->assertOk();
    }

    public function test_import_table_lists_the_bookings_and_searches_by_container(): void
    {
        $this->actingAs($this->user('booking'));
        foreach (['IMP1' => 'AAA1', 'IMP2' => 'BBB2'] as $booking => $container) {
            $id = DB::table('embarque')->insertGetId(['booking' => $booking, 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'finalizado' => 'n']);
            DB::table('departure_confirmation')->insert(['ref_embarque' => $id, 'created_by' => 1]);
            $bl = DB::table('bl')->insertGetId(['embarque_id' => $id]);
            DB::table('container')->insert(['bl_id' => $bl, 'numero' => $container]);
        }

        Livewire::test(ImportTrackingControl::class)
            ->assertSee('IMP1')
            ->assertSee('IMP2')
            ->searchTable('BBB2')
            ->assertSee('IMP2')
            ->assertDontSee('IMP1');
    }
}
