<?php

namespace Tests\Feature\Filament;

use App\Filament\Livewire\QuotationHistoryChart;
use App\Filament\Livewire\ReportSectionTable;
use App\Filament\Pages\QuotationHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class QuotationHistoryTest extends TestCase
{
    use LegacySchema;

    private const FILTERS = ['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31', 'tipo' => 'impo', 'modal' => 'maritimo', 'tipo_data' => 'cadastro', 'agrupamento' => ['equipamento'], 'exibir_lista' => true, 'pais_origem_ids' => [1], 'despesa_ids' => [1]];

    public function test_only_users_with_import_export_and_manager_open_the_page(): void
    {
        $this->actingAs($this->user('import', 'manager'))->get(QuotationHistory::getUrl())->assertForbidden();
        $this->actingAs($this->user('import', 'export', 'manager'))->get(QuotationHistory::getUrl())->assertOk();
    }

    public function test_warns_when_the_filters_are_incomplete(): void
    {
        $this->actingAs($this->user('import', 'export', 'manager'));

        Livewire::test(QuotationHistory::class)
            ->call('send')
            ->assertNotified('Informe a data inicial e a data final.');
    }

    public function test_sends_the_report_and_shows_the_summary_and_the_list(): void
    {
        $this->actingAs($this->user('import', 'export', 'manager'));
        $this->quotation();

        Livewire::test(QuotationHistory::class)
            ->fillForm(self::FILTERS)
            ->call('send')
            ->assertSeeLivewire(ReportSectionTable::class)
            ->assertSeeLivewire(QuotationHistoryChart::class)
            ->assertSet('data.chart.type', 'barra')
            ->assertSet('data.chart.metrics', ['media'])
            ->assertSee(['Tipo do gráfico', 'Eixo X', 'Valores (eixo Y)'])
            ->set('data.chart.type', 'linha')
            ->assertSeeLivewire(QuotationHistoryChart::class);

        $filters = ['cliente_id' => null, 'tipo_carga' => null, 'regra_ids' => [], 'equipamento_ids' => []] + self::FILTERS;
        $section = fn (string $name) => Livewire::test(ReportSectionTable::class, ['page' => QuotationHistory::class, 'section' => $name, 'filters' => $filters]);

        $section('summary')->assertSee(['Impo — 01/10/2026 até 31/10/2026 — 1 registros', "40' HC", 'USD', '100,00']);
        $section('list')->assertSee(['1 — REF-1', 'Cliente A (123)', 'Santos (BRSSZ) / Brasil', 'THC', '05/10/2026', '31/12/2026']);

        Livewire::test(QuotationHistoryChart::class, ['applied' => $filters, 'chartOptions' => ['x_axis' => ['moeda'], 'type' => 'barra']])
            ->assertSee(['Gráfico', 'USD']);
    }

    public function test_the_list_links_to_the_quotation_screen_of_the_legacy_and_is_paginated(): void
    {
        config(['services.tracking.url' => 'https://tracking.test']);
        $this->quotation();

        foreach (range(1, 60) as $value) {
            DB::table('cotacao_impo_item')->insert(['cotacao_impo_id' => 1, 'despesa_id' => 1, 'moeda_compra_id' => 1, 'moeda_venda_id' => 1, 'valor_venda' => 100 + $value, 'tipo_container_id' => 1]);
        }

        $filters = ['cliente_id' => null, 'tipo_carga' => null, 'regra_ids' => [], 'equipamento_ids' => []] + self::FILTERS;

        Livewire::test(ReportSectionTable::class, ['page' => QuotationHistory::class, 'section' => 'list', 'filters' => $filters])
            ->assertSeeHtml('https://tracking.test/gerenciador.php/cotacaoImpo/show?id=1')
            ->assertCanSeeTableRecords(['0', '49'])
            ->assertCanNotSeeTableRecords(['50']);
    }

    public function test_no_rows_say_so_and_excel_downloads(): void
    {
        $this->actingAs($this->user('import', 'export', 'manager'));
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Brasil']);
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'THC']);

        Livewire::test(QuotationHistory::class)
            ->fillForm(self::FILTERS)
            ->call('send')
            ->assertSee('Nenhum registro encontrado para os filtros informados.')
            ->call('excel', true)
            ->assertFileDownloaded()
            ->call('excel', false, true)
            ->assertFileDownloaded();
    }

    private function quotation(): void
    {
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Brasil']);
        DB::table('porto')->insert(['porto_id' => 1, 'nome' => 'Santos', 'uncode' => 'BRSSZ', 'ref_pais' => 1]);
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente A', 'cnpj' => '123']);
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'THC']);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => "40' HC", 'tipo' => '40', 'tipo_embarque' => 'm']);
        DB::table('cmn_currencies')->insert(['id' => 1, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('cotacao_impo')->insert(['cotacao_impo_id' => 1, 'pessoa_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 1, 'transit_time' => 1, 'free_time' => 1, 'armador_id' => 1, 'incoterm_id' => 1, 'validade_max' => '2026-12-31', 'referencia' => 'REF-1', 'created_at' => '2026-10-05 10:00:00']);
        DB::table('cotacao_impo_item')->insert(['cotacao_impo_id' => 1, 'despesa_id' => 1, 'moeda_compra_id' => 1, 'moeda_venda_id' => 1, 'valor_venda' => 100, 'tipo_container_id' => 1]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
