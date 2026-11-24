<?php

namespace Tests\Feature\Filament;

use App\Actions\Seguro\AllowInsuranceCharge;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\Pages\ListSeguros;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Models\Embarque;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class InsuranceBookingTest extends TestCase
{
    use LegacySchema;

    public function test_menu_links_the_insurance_list_for_the_booking_credential(): void
    {
        $this->actingAs($this->user('booking'))
            ->get(EmbarqueResource::getUrl())
            ->assertOk()
            ->assertSee('Lista de Seguros')
            ->assertSee(EmbarqueResource::getUrl('seguros'), false);

        $this->actingAs($this->user('financial'))->get(EmbarqueResource::getUrl('seguros'))->assertForbidden();
    }

    public function test_insurance_list_shows_only_insurance_bookings_with_the_legacy_columns(): void
    {
        $this->actingAs($this->user('booking'));
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Exportadora Sul'], ['pessoa_id' => 2, 'nome' => 'Importadora Norte'], ['pessoa_id' => 3, 'nome' => 'Agente Hamburgo']]);
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Brasil']);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos', 'ref_pais' => 1], ['porto_id' => 2, 'nome' => 'Hamburgo', 'ref_pais' => 1]]);
        $insurance = $this->booking([
            'booking' => 'SEG1', 'tipo_embarque' => 's', 'impor_expor' => 'i', 'finalizado' => 'n', 'invoice' => 'INV-9', 'customer_ref' => 'REF-7',
            'data_topo' => '2026-11-02', 'saida_prevista' => '2026-11-10', 'chegada_prevista' => '2026-11-25', 'via' => 'MSC',
            'pessoa_id_cliente' => 1, 'pessoa_id_importador' => 2, 'pessoa_id_representante' => 3, 'porto_origem_id' => 1, 'porto_destino_id' => 2,
        ]);
        $sea = $this->booking(['booking' => 'MAR1', 'tipo_embarque' => 'm', 'cliente_id' => 1]);

        Livewire::test(ListSeguros::class)
            ->assertCanSeeTableRecords([$insurance])
            ->assertCanNotSeeTableRecords([$sea])
            ->assertSeeInOrder(['SEG1', 'Importação', 'Seguro', 'Aberto', 'INV-9', 'REF-7'])
            ->assertSee(['DATA: 02/11/2026', 'ETD: 10/11/2026', 'ETA: 25/11/2026', 'Exportador: Exportadora Sul', 'Importador: Importadora Norte', 'Agente: Agente Hamburgo', 'Origem: Santos', 'Destino: Hamburgo', 'Via: MSC']);
    }

    public function test_insurance_list_searches_by_party_and_filters_by_period_and_request_date(): void
    {
        $this->actingAs($this->user('booking'));
        $this->travelTo('2026-11-18');
        $acme = DB::table('pessoa')->insertGetId(['nome' => 'ACME']);
        $today = $this->booking(['booking' => 'SEG-HOJE', 'tipo_embarque' => 's', 'data_topo' => '2026-11-18', 'pessoa_id_cliente' => $acme]);
        $week = $this->booking(['booking' => 'SEG-SEMANA', 'tipo_embarque' => 's', 'data_topo' => '2026-11-16']);
        $old = $this->booking(['booking' => 'SEG-ANTIGO', 'tipo_embarque' => 's', 'data_topo' => '2026-10-02']);

        Livewire::test(ListSeguros::class)
            ->assertCanSeeTableRecords([$today, $week, $old])
            ->searchTable('ACME')
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$week, $old])
            ->searchTable('')
            ->filterTable('exibir', 'hoje')
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$week, $old])
            ->filterTable('exibir', 'semana')
            ->assertCanSeeTableRecords([$today, $week])
            ->assertCanNotSeeTableRecords([$old])
            ->removeTableFilter('exibir')
            ->filterTable('data_topo', ['data_topo' => '2026-10-02'])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$today, $week]);
    }

    public function test_view_shows_the_insurance_company_values_and_charge_status_only_on_insurance_bookings(): void
    {
        $this->actingAs($this->user('booking'));
        DB::table('cia_seguro')->insert(['id' => 1, 'name' => 'Porto Seguro']);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        $insurance = $this->booking(['tipo_embarque' => 's']);
        $this->confirmation($insurance, ['ref_cia_seguro' => 1, 'valor_fatura' => 10000, 'valor_frete' => 1500.5, 'despesas' => 250, 'ref_currency_fatura' => 2, 'ref_currency_frete' => 2, 'ref_currency_despesa' => 2]);
        $sea = $this->booking(['tipo_embarque' => 'm']);

        Livewire::test(ViewEmbarque::class, ['record' => $insurance->getKey()])
            ->assertSee(['Porto Seguro', 'US$ 10.000,00', 'US$ 1.500,50', 'US$ 250,00', 'Aguardando habilitação']);

        Livewire::test(ViewEmbarque::class, ['record' => $sea->getKey()])
            ->assertDontSee(['Cia Seguro', 'Aguardando habilitação']);
    }

    public function test_allow_charge_action_enables_the_insurance_and_then_disappears(): void
    {
        $this->actingAs($this->user('booking'));
        $insurance = $this->booking(['tipo_embarque' => 's']);
        $this->confirmation($insurance);

        Livewire::test(ViewEmbarque::class, ['record' => $insurance->getKey()])
            ->assertActionVisible('permitirCobranca')
            ->callAction('permitirCobranca')
            ->assertActionHidden('permitirCobranca')
            ->assertSee('Habilitada');

        $this->assertSame(1, (int) DB::table('insurance_confirmation')->value('control'));
    }

    public function test_allow_charge_action_is_hidden_on_other_types_and_without_insurance_data(): void
    {
        $this->actingAs($this->user('booking'));
        $sea = $this->booking(['tipo_embarque' => 'm']);
        $this->confirmation($sea);
        $withoutData = $this->booking(['tipo_embarque' => 's']);

        Livewire::test(ViewEmbarque::class, ['record' => $sea->getKey()])->assertActionHidden('permitirCobranca');
        Livewire::test(ViewEmbarque::class, ['record' => $withoutData->getKey()])->assertActionHidden('permitirCobranca');

        $this->expectException(DomainException::class);
        app(AllowInsuranceCharge::class)($withoutData);
    }

    /** @param  array<string, mixed>  $attributes */
    private function confirmation(Embarque $booking, array $attributes = []): void
    {
        DB::table('insurance_confirmation')->insert($attributes + ['ref_embarque' => $booking->getKey(), 'control' => 0, 'created_by' => 1]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'BKG'.random_int(1000, 9999), 'tipo_embarque' => 'm', 'cliente_id' => 1]));
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
