<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ControlePas\ControlePaResource;
use App\Filament\Resources\ControlePas\Pages\ListControlePas;
use App\Filament\Resources\ControlePas\Pages\ViewControlePa;
use App\Models\ControlePa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ControlePaResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_financial_credential(): void
    {
        $this->actingAs($this->user('comercial'))
            ->get(ControlePaResource::getUrl())
            ->assertForbidden();
    }

    public function test_lists_open_and_closed_tabs_without_canceled_bookings(): void
    {
        $this->actingAs($this->user());
        $open = $this->row(1, ['flag_fi_dmf' => 1]);
        $old = $this->row(2, ['flag_fi_dmf' => 1], saidaPrevista: '2019-12-31');
        $canceled = $this->row(3, ['flag_fi_dmf' => 1], finalizado: 'c');
        $closed = $this->row(4, ['flag_fi_dmf' => 2, 'data_envio_fi_agente' => '2026-11-02', 'flag_fi_armador' => 1, 'data_pgto_armador' => '2026-11-03', 'data_recibo_armador' => '2026-11-04']);

        Livewire::test(ListControlePas::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$old, $canceled, $closed])
            ->set('activeTab', 'fechados')
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$open, $canceled]);
    }

    public function test_view_offers_only_the_next_step_and_saves_the_date(): void
    {
        $this->actingAs($this->user());
        Storage::fake('tracking_uploads');
        $row = $this->row(1, ['flag_fi_dmf' => 1]);

        Livewire::test(ViewControlePa::class, ['record' => $row->getKey()])
            ->assertActionVisible('dataEnvioAgente')
            ->assertActionHidden('anexarFiArmador')
            ->assertActionHidden('dataPagamento')
            ->callAction('dataEnvioAgente', ['data' => '2026-11-02'])
            ->assertNotified('Data salva.')
            ->assertActionVisible('anexarFiArmador');

        $this->assertSame('2026-11-02', $row->fresh()->data_envio_fi_agente->toDateString());
    }

    /** @param  array<string, mixed>  $attributes */
    private function row(int $bookingId, array $attributes, string $saidaPrevista = '2026-11-01', string $finalizado = 'n'): ControlePa
    {
        DB::table('embarque')->insert(['embarque_id' => $bookingId, 'booking' => "BKG{$bookingId}", 'saida_prevista' => $saidaPrevista, 'finalizado' => $finalizado]);

        return ControlePa::forceCreate(['embarque_id' => $bookingId, ...$attributes]);
    }

    private function user(string $credentials = 'financial'): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);

        return User::find($id);
    }
}
