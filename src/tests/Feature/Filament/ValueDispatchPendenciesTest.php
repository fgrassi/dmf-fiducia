<?php

namespace Tests\Feature\Filament;

use App\Actions\Booking\GenerateValueDispatchPendency;
use App\Actions\Reports\ValueDispatchPendenciesReport;
use App\Filament\Pages\ValueDispatchPendencies;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ValueDispatchPendenciesTest extends TestCase
{
    use LegacySchema;

    public function test_only_followup_opens_the_page(): void
    {
        $this->actingAs($this->user('financial'))->get(ValueDispatchPendencies::getUrl())->assertForbidden();
        $this->actingAs($this->user('followup'))->get(ValueDispatchPendencies::getUrl())->assertOk();
    }

    public function test_lists_the_controls_newest_first_and_filters_by_status_booking_and_release_day(): void
    {
        $releaser = $this->user('followup');
        $this->actingAs($releaser);
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Cliente A'], ['pessoa_id' => 2, 'nome' => 'Cliente B']]);
        DB::table('embarque')->insert([['embarque_id' => 1, 'booking' => 'BKG-1', 'cliente_id' => 1], ['embarque_id' => 2, 'booking' => 'BKG-2', 'cliente_id' => 2], ['embarque_id' => 3, 'booking' => 'BKG-3', 'cliente_id' => 1]]);
        DB::table('controle_envio_valores')->insert([
            ['embarque_id' => 1, 'status' => 2, 'updated_by' => $releaser->getKey(), 'updated_at' => '2026-11-10'],
            ['embarque_id' => 2, 'status' => 1, 'updated_by' => null, 'updated_at' => null],
            ['embarque_id' => 3, 'status' => 2, 'updated_by' => $releaser->getKey(), 'updated_at' => '2026-11-12'],
        ]);

        $report = app(ValueDispatchPendenciesReport::class);

        $this->assertSame(['BKG-3', 'BKG-2', 'BKG-1'], array_column($report(), 'booking'));
        $this->assertSame(['BKG-2'], array_column($report(['status' => 1]), 'booking'));
        $this->assertSame(['BKG-3', 'BKG-1'], array_column($report(['status' => 2]), 'booking'));
        $this->assertSame(['BKG-1'], array_column($report(['embarque_id' => 1]), 'booking'));
        $this->assertSame(['BKG-3'], array_column($report(['data' => '2026-11-12']), 'booking'));
        $this->assertSame(
            ['id' => 2, 'booking' => 'BKG-2', 'client' => 'Cliente B', 'status' => 'Pendente', 'released_by' => '', 'released_on' => null],
            $report(['status' => 1])[0],
        );

        Livewire::test(ValueDispatchPendencies::class)
            ->assertSee(['BKG-1', 'Cliente A', 'Liberado', 'Pendente', 'Ana', '10/11/2026'])
            ->set('tableDeferredFilters.busca', ['status' => 1])
            ->call('applyTableFilters')
            ->assertSee('BKG-2')
            ->assertDontSee(['BKG-1', 'BKG-3']);
    }

    public function test_generation_only_for_flagged_clients_and_never_twice(): void
    {
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Controlado', 'ctr_envio_valores' => 's'], ['pessoa_id' => 2, 'nome' => 'Livre', 'ctr_envio_valores' => 'n']]);
        DB::table('embarque')->insert([['embarque_id' => 1, 'booking' => 'BKG-1', 'cliente_id' => 1], ['embarque_id' => 2, 'booking' => 'BKG-2', 'cliente_id' => 2]]);

        $generate = app(GenerateValueDispatchPendency::class);

        $this->assertTrue($generate(1));
        $this->assertFalse($generate(1));
        $this->assertFalse($generate(2));
        $this->assertSame([['embarque_id' => 1, 'status' => 1]], DB::table('controle_envio_valores')->get(['embarque_id', 'status'])->map(fn ($r) => (array) $r)->all());
    }

    public function test_release_marks_only_the_pending_control_and_needs_lib_val_des(): void
    {
        $this->travelTo('2026-11-16 09:30');
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente A']);
        DB::table('embarque')->insert([['embarque_id' => 1, 'booking' => 'BKG-1', 'cliente_id' => 1], ['embarque_id' => 2, 'booking' => 'BKG-2', 'cliente_id' => 1]]);
        DB::table('controle_envio_valores')->insert([['embarque_id' => 1, 'status' => 1, 'updated_by' => null, 'updated_at' => null], ['embarque_id' => 2, 'status' => 1, 'updated_by' => null, 'updated_at' => null]]);

        $this->actingAs($this->user('followup'));
        Livewire::test(ValueDispatchPendencies::class)->assertTableActionHidden('liberar', '1');

        $releaser = $this->user('followup', 'lib_val_des');
        $this->actingAs($releaser);
        Livewire::test(ValueDispatchPendencies::class)->callTableAction('liberar', '1');

        $row = DB::table('controle_envio_valores')->where('embarque_id', 1)->first();
        $this->assertSame([2, $releaser->getKey(), '2026-11-16 09:30:00'], [(int) $row->status, (int) $row->updated_by, $row->updated_at]);
        $this->assertSame(1, (int) DB::table('controle_envio_valores')->where('embarque_id', 2)->value('status'));
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
