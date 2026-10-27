<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Charges\ChargeResource;
use App\Filament\Resources\Charges\Pages\ListCharges;
use App\Models\FinCharges;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ChargeResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_financial_credential(): void
    {
        $this->actingAs($this->user('comercial'))
            ->get(ChargeResource::getUrl())
            ->assertForbidden();
    }

    public function test_open_tab_lists_open_charges_and_paid_tab_lists_settled_ones(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG1']);
        $open = $this->charge(status: 0);
        $paid = $this->charge(status: 1);

        Livewire::test(ListCharges::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$paid])
            ->set('activeTab', 'pagos')
            ->assertCanSeeTableRecords([$paid])
            ->assertCanNotSeeTableRecords([$open]);
    }

    private function user(string $credentials): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);

        return User::find($id);
    }

    private function charge(int $status): FinCharges
    {
        $id = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => 10, 'status' => $status, 'created_at' => now()->toDateString()]);

        return FinCharges::find($id);
    }
}
