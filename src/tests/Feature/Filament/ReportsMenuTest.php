<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ArrivalConfirmations;
use App\Filament\Pages\BookingsBySeller;
use App\Filament\Pages\BookingsReportPage;
use App\Filament\Pages\DailyMovement;
use App\Filament\Pages\DepartureConfirmations;
use App\Filament\Pages\LocalCharges;
use App\Filament\Pages\NegotiatedRates;
use App\Filament\Pages\TransitTimeByCarrier;
use App\Filament\Pages\TransitTimeByPort;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ReportsMenuTest extends TestCase
{
    use LegacySchema;

    public function test_menu_groups_the_reports_under_relatorios_by_credential(): void
    {
        $this->actingAs($this->user('manager,relatorio,booking,followup'));
        Filament::setCurrentPanel('admin');

        $group = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Relatórios');

        $this->assertEqualsCanonicalizing(['Taxas Negociadas', 'Taxas Locais', 'Movimento Diário', 'Transit Time - Armador', 'Transit Time - Destino', 'Reserva/Vendedor', 'Reservas', 'Confirmações de Chegada', 'Confirmações de Embarque', 'Pendências Debit/Credit Notes', 'Pendências - Envio de Valores', 'Instruções Especiais Expo', 'Matriz Despesa Pessoa'], collect($group->getItems())->map->getLabel()->all());
    }

    public function test_pages_forbid_users_without_the_legacy_credential(): void
    {
        $this->actingAs($this->user('financial'));

        $this->get(ArrivalConfirmations::getUrl())->assertForbidden();
        $this->get(DepartureConfirmations::getUrl())->assertForbidden();
        $this->get(NegotiatedRates::getUrl())->assertForbidden();
        $this->get(TransitTimeByCarrier::getUrl())->assertForbidden();
        $this->get(TransitTimeByPort::getUrl())->assertForbidden();
        $this->get(DailyMovement::getUrl())->assertForbidden();
        $this->get(BookingsBySeller::getUrl())->assertForbidden();
        $this->get(BookingsReportPage::getUrl())->assertForbidden();
        $this->actingAs($this->user('booking'));
        $this->get(LocalCharges::getUrl())->assertOk();
        $this->get(ArrivalConfirmations::getUrl())->assertOk();
    }

    public function test_negotiated_rates_lists_the_default_period(): void
    {
        $this->actingAs($this->user('manager'));
        $this->travelTo('2026-11-16 10:00');
        $charge = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => DB::table('embarque')->insertGetId(['booking' => 'IMP1', 'impor_expor' => 'i']), 'total_value' => 10, 'created_at' => '2026-11-01']);
        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $charge, 'note' => 'combinado', 'created_at' => '2026-11-10', 'created_by' => 7, 'custom_rate' => 5.25]);

        Livewire::test(NegotiatedRates::class)
            ->assertSee(['IMP1', 'combinado', '5,25']);
    }

    public function test_confirmations_list_the_default_period(): void
    {
        $this->actingAs($this->user('booking'));
        $this->travelTo('2026-11-16 10:00');
        $id = DB::table('embarque')->insertGetId(['booking' => 'IMP9', 'impor_expor' => 'i', 'chegada_prevista' => '2026-11-10']);
        DB::table('arrival_confirmation')->insert(['ref_embarque' => $id, 'created_by' => 1, 'created_at' => '2026-11-05']);

        Livewire::test(ArrivalConfirmations::class)->assertSee('IMP9');
        Livewire::test(DepartureConfirmations::class)->assertDontSee('IMP9');
    }

    public function test_local_charges_search_renders_the_groups(): void
    {
        $this->actingAs($this->user('booking'));
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK']);
        DB::table('porto')->insert(['porto_id' => 1, 'nome' => 'Santos']);
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'THC', 'ref_unity' => null]);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('cmn_armador_porto')->insert(['id' => 1, 'ref_armador' => 1, 'ref_porto' => 1]);
        DB::table('fin_expenses_matrix')->insert(['ref_armador_porto' => 1, 'ref_expense' => 1, 'type' => 'i', 'expense_value' => 99, 'imo_value' => 0, 'created_by' => 1, 'ref_currency' => 2, 'billing' => 'p', 'due_from' => '2026-01-01']);

        Livewire::test(LocalCharges::class)
            ->assertSee(['MAERSK', 'Santos', 'THC', 'US$ 99,00']);
    }

    public function test_transit_time_pages_wait_for_their_filters_and_export_the_result(): void
    {
        $this->actingAs($this->user('relatorio'));
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Brasil']);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos', 'ref_pais' => 1], ['porto_id' => 2, 'nome' => 'Roterdã', 'ref_pais' => 1]]);
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'MAERSK']);
        DB::table('embarque')->insert(['booking' => 'B', 'tipo_embarque' => 'm', 'armador_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'etd_inicial' => '2026-10-01', 'eta_inicial' => '2026-10-21', 'saida_efetiva' => '2026-10-02', 'chegada_efetiva' => '2026-10-27']);

        Livewire::test(TransitTimeByCarrier::class)
            ->assertSee('Informe a data inicial da saída efetiva')
            ->assertActionHidden('export')
            ->set('tableFilters.periodo.data_ini', '2026-10-01')
            ->assertSee(['MAERSK', 'Santos', 'Roterdã', '20,00', '25,00', '5,00'])
            ->callAction('export')
            ->assertFileDownloaded();

        Livewire::test(TransitTimeByPort::class)
            ->assertSee('Informe a origem e/ou o destino')
            ->assertSuccessful();
    }

    public function test_daily_movement_opens_on_today_and_shows_the_totals(): void
    {
        $this->actingAs($this->user('director'));
        $this->travelTo('2026-11-16 10:00');
        DB::table('embarque')->insert(['booking' => 'EXP7', 'tipo_embarque' => 'm', 'impor_expor' => 'e', 'dteCadastro' => '2026-11-16', 'finalizado' => 'n']);

        Livewire::test(DailyMovement::class)
            ->assertSee(['EXP7', 'Exportação', 'Cotações Expo', 'Cotações Impo Aéreo']);
    }

    public function test_bookings_by_seller_shows_the_four_lists_after_generating(): void
    {
        $this->actingAs($this->user('relatorio'));
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'ACME']);
        DB::table('embarque')->insert(['booking' => 'IMP1', 'cliente_id' => 1, 'finalizado' => 'n', 'tipo_embarque' => 'm', 'impor_expor' => 'i', 'data_topo' => '2026-11-02']);

        Livewire::test(BookingsBySeller::class)
            ->assertDontSee('Fechamentos do Período')
            ->fillForm(['data_ini' => '2026-11-01', 'data_fim' => '2026-11-30'])
            ->call('send')
            ->assertSee(['Fechamentos do Período', 'IMPOS Confirmadas', 'EXPOS Confirmadas', 'PAGAMENTOS recebidos', 'IMP1', 'ACME', 'Marítimo']);
    }

    public function test_bookings_report_shows_the_chosen_sections_and_exports_them(): void
    {
        $this->actingAs($this->user('relatorio'));
        DB::table('embarque')->insert(['booking' => 'FCL1', 'tipo_embarque' => 'm', 'finalizado' => 'n', 'data_topo' => '2026-11-10']);
        DB::table('embarque')->insert(['booking' => 'DES1', 'tipo_embarque' => 'd', 'finalizado' => 'n', 'data_topo' => '2026-11-10']);

        Livewire::test(BookingsReportPage::class)
            ->assertActionHidden('export')
            ->fillForm(['from' => '2026-11-01', 'to' => '2026-11-30', 'sections' => ['maritime', 'clearance']])
            ->call('send')
            ->assertSee(['Reservas Marítimo', 'FCL1', 'Reservas Desembaraço', 'DES1'])
            ->assertDontSee('Reservas Aéreo')
            ->callAction('export')
            ->assertFileDownloaded();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
