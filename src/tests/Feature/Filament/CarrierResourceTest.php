<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Carriers\Pages\ManageCarriers;
use App\Filament\Resources\Carriers\Pages\ViewCarrier;
use App\Models\Armador;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CarrierResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_and_deletes_carrier(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManageCarriers::class)
            ->callAction('create', ['nome' => 'Maersk', 'scac' => 'MAEU', 'recalcular' => 1, 'integracao_portal' => 0])
            ->assertHasNoFormErrors();

        $carrier = Armador::sole();
        $this->assertSame('MAEU', $carrier->scac);
        $this->assertSame('{"carrierBookingReference":"reserva.booking"}', $carrier->integracao_portal_refs_impo);

        Livewire::test(ManageCarriers::class)
            ->callTableAction(DeleteAction::class, $carrier);

        $this->assertNull($carrier->fresh());
    }

    public function test_view_shows_legacy_data_and_edits_in_place(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $carrier = Armador::create(['nome' => 'Hapag', 'scac' => 'HLCU', 'invoice_impo' => 1]);

        Livewire::test(ViewCarrier::class, ['record' => $carrier->getKey()])
            ->assertSee(['Hapag', 'HLCU'])
            ->callAction('edit', ['nome' => 'Hapag-Lloyd', 'recalcular' => 1, 'integracao_portal' => 0])
            ->assertHasNoFormErrors();

        $this->assertSame('Hapag-Lloyd', $carrier->fresh()->nome);
    }

    public function test_rich_text_fields_render_as_html(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $carrier = Armador::create(['nome' => 'Hapag', 'obs' => '<p>Prazo m&aacute;ximo de <strong>7 dias</strong></p>']);

        Livewire::test(ViewCarrier::class, ['record' => $carrier->getKey()])
            ->assertSeeHtml('<strong>7 dias</strong>')
            ->assertDontSee('&lt;p&gt;', false)
            ->callAction('edit', ['obs' => '<p>Novo</p>', 'recalcular' => 1, 'integracao_portal' => 0])
            ->assertHasNoFormErrors();

        $this->assertStringContainsString('Novo', $carrier->fresh()->obs);
    }

    public function test_edit_saves_portal_map_per_operation_and_shows_it(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $carrier = Armador::create(['nome' => 'HMM', 'integracao_portal' => 1]);

        Livewire::test(ViewCarrier::class, ['record' => $carrier->getKey()])
            ->assertSee('Booking armador ← reserva.booking')
            ->callAction('edit', [
                'recalcular' => 1,
                'integracao_portal' => 1,
                'portal_refs' => [
                    'impo' => ['carrierBookingReference' => 'bl.mbl', 'equipmentReference' => 'bl.equipamento.nr_container'],
                    'expo' => ['carrierBookingReference' => 'reserva.booking'],
                ],
            ])
            ->assertHasNoFormErrors()
            ->assertSee('Booking armador ← bl.mbl · Container ← bl.equipamento.nr_container');

        $carrier->refresh();
        $this->assertSame('{"carrierBookingReference":"bl.mbl","equipmentReference":"bl.equipamento.nr_container"}', $carrier->integracao_portal_refs_impo);
        $this->assertSame('{"carrierBookingReference":"reserva.booking"}', $carrier->integracao_portal_refs_expo);
    }

    public function test_edit_form_is_filled_with_stored_portal_map(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $carrier = Armador::create(['nome' => 'HMM', 'integracao_portal_refs_impo' => '["carrierBookingReference","equipmentReference"]']);

        Livewire::test(ViewCarrier::class, ['record' => $carrier->getKey()])
            ->mountAction('edit')
            ->assertSchemaStateSet([
                'portal_refs.impo.carrierBookingReference' => 'reserva.booking',
                'portal_refs.impo.equipmentReference' => 'bl.equipamento.nr_container',
                'portal_refs.expo.carrierBookingReference' => 'reserva.booking',
            ]);
    }

    public function test_carrier_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $carrier = Armador::create(['nome' => 'Hapag']);
        DB::table('controle_invoice_master')->insert(['embarque_id' => 1, 'armador_id' => $carrier->armador_id, 'mbl_number' => 'X', 'status' => 0, 'created_at' => now(), 'created_by' => 1]);

        Livewire::test(ManageCarriers::class)
            ->assertTableActionHidden(DeleteAction::class, $carrier);
    }

    public function test_requires_auxiliares_credential(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageCarriers::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
