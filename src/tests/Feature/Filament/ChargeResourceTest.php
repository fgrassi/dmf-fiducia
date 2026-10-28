<?php

namespace Tests\Feature\Filament;

use App\Actions\Cobranca\Nfse\SyncPendingNfse;
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

    public function test_list_syncs_nfse_of_the_displayed_page_once_and_limits_api_calls(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert([['embarque_id' => 10, 'booking' => 'BKG1'], ['embarque_id' => 11, 'booking' => 'BKG2']]);
        $first = $this->charge(status: 0);
        $second = $this->charge(status: 0, booking: 11);
        $refreshed = [];
        $emailed = [];
        $this->mock(SyncPendingNfse::class, function ($mock) use (&$refreshed, &$emailed) {
            $mock->shouldReceive('refresh')->andReturnUsing(function (FinCharges $c) use (&$refreshed) {
                $refreshed[] = $c->id;

                return true;
            });
            $mock->shouldReceive('retryEmail')->andReturnUsing(function (FinCharges $c) use (&$emailed) {
                $emailed[] = $c->id;

                return false;
            });
        });

        $list = Livewire::test(ListCharges::class);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $refreshed);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $emailed);

        [$refreshed, $emailed] = [[], []];
        $list->call('$refresh');
        $this->assertSame([], $refreshed);

        $list->filterTable('busca', ['booking' => 'BKG2']);
        $this->assertSame([$second->id], $refreshed);
        $this->assertSame([$second->id], $emailed);

        $list->filterTable('busca', []);
        for ($i = 0; $i < 16; $i++) {
            $this->charge(status: 0);
        }
        [$refreshed, $emailed] = [[], []];
        Livewire::test(ListCharges::class);
        $this->assertCount(15, $refreshed);
        $this->assertCount(18, $emailed);
    }

    private function user(string $credentials): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);

        return User::find($id);
    }

    private function charge(int $status, int $booking = 10): FinCharges
    {
        $id = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => $booking, 'status' => $status, 'created_at' => now()->toDateString()]);

        return FinCharges::find($id);
    }
}
