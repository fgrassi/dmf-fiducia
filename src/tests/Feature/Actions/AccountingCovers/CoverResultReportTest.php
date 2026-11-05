<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\CoverResultReport;
use App\Actions\AccountingCovers\ExportCoverResultReport;
use App\Models\CapaProcesso;
use App\Models\CapaProcessoManual;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CoverResultReportTest extends TestCase
{
    use LegacySchema;

    public function test_does_not_search_without_a_filter(): void
    {
        $this->summaries();

        $report = app(CoverResultReport::class)(['commodity' => '', 'folder_id' => null], ['cliente']);

        $this->assertSame([], $report['lines']);
        $this->assertSame([], $report['ranking']);
    }

    public function test_computes_the_result_of_normal_and_manual_covers_with_the_legacy_formula(): void
    {
        $this->summaries();

        $report = app(CoverResultReport::class)(['folder_id' => 1]);

        // normal: rs 600 + taxa (100 + 20) * 5 + custo financeiro invertido (+12); manual: rs 50 + 10 EUR * 6
        $this->assertEquals(['usd' => 100, 'tx_usd' => 600, 'rs' => 650, 'euro' => 10, 'tx_euro' => 60, 'financial_cost' => 12, 'result' => 1322], $report['total']);
        $this->assertEquals(['Cliente SA' => 1212, 'Cliente Manual' => 110], $report['ranking']);
        $this->assertEquals(['usd' => 100, 'tx_usd' => 600, 'rs' => 0, 'euro' => 0, 'tx_euro' => 0, 'DEM' => 1212, 'DET' => 0, 'COU' => 0, 'SEG' => 0], $report['offices']['e']['Porto Alegre']);
        $this->assertEquals(['euro' => 10, 'tx_euro' => 60, 'rs' => 0, 'COU' => 110], array_intersect_key($report['offices']['i']['N/D'], array_flip(['euro', 'tx_euro', 'rs', 'COU'])));
    }

    public function test_separates_demurrage_and_courier_and_filters_by_them(): void
    {
        $this->summaries();

        $report = app(CoverResultReport::class)(['folder_id' => 1]);

        $this->assertEquals(['i' => 0, 'e' => 1212], $report['groups']['DEM']);
        $this->assertEquals(['i' => 110, 'e' => 0], $report['groups']['COU']);
        $this->assertSame(['COU', 'DEM'], collect($report['lines'])->pluck('row.category')->all());

        $onlyCourier = app(CoverResultReport::class)(['folder_id' => 1], [], ['COU']);

        $this->assertSame(['Cliente Manual'], array_keys($onlyCourier['ranking']));
        $this->assertEquals(0, $onlyCourier['groups']['DEM']['e']);
    }

    public function test_adds_a_subtotal_after_each_group_with_the_lower_levels_closed_first(): void
    {
        $this->summaries();
        DB::table('resumo_resultado')->insert(['capa_processo_id' => 99, 'cliente' => 'Cliente SA', 'impo_expo' => 'i', 'tipo_embarque' => 'm', 'usd' => 0, 'rs' => 5, 'euro' => 0, 'data_pagamento' => '2026-08-12']);
        DB::table('capa_processo')->insert(['capa_process_id' => 99, 'embarque_id' => 1, 'moeda_id' => 1, 'capa_processo_arquivo_id' => 1]);

        $lines = app(CoverResultReport::class)(['folder_id' => 1], ['cliente', 'impo_expo'])['lines'];

        $this->assertSame(
            ['Cliente Manual', 'TOTAL N/D', 'TOTAL Cliente Manual', 'Cliente SA', 'TOTAL EXPORTAÇÃO', 'Cliente SA', 'TOTAL IMPORTAÇÃO', 'TOTAL Cliente SA'],
            collect($lines)->map(fn (array $line) => $line['subtotal'] ?? $line['row']->client)->all(),
        );
        $this->assertEquals(605, collect($lines)->last()['sum']['rs']);
    }

    public function test_filters_by_period_and_house_bl(): void
    {
        $this->summaries();

        $this->assertSame(['Cliente SA'], array_keys(app(CoverResultReport::class)(['from' => '2026-08-01', 'until' => '2026-08-10'])['ranking']));
        $this->assertSame(['Cliente SA'], array_keys(app(CoverResultReport::class)(['house_bl' => 'abc'])['ranking']));
    }

    public function test_shows_the_quotation_reference_by_operation_and_type(): void
    {
        $this->summaries();
        DB::table('usuario')->insert(['usuario_id' => 1, 'nome' => 'Ana de Souza', 'login' => 'ana', 'senha' => 'x', 'status' => 1]);
        DB::table('porto')->insert([['porto_id' => 1, 'uncode' => 'BRSSZ'], ['porto_id' => 2, 'uncode' => 'DEHAM']]);
        DB::table('cotacao_expo')->insert(['cotacao_expo_id' => 5, 'pessoa_id' => 1, 'transit_time' => 10, 'armador_id' => 1, 'validade' => '2026-04-01', 'created_by' => 1, 'created_at' => '2026-03-01 10:00:00', 'porto_origem_id' => 1, 'porto_destino_id' => 2]);
        DB::table('cotacao_impo')->insert(['cotacao_impo_id' => 3, 'referencia' => 'IMP-3', 'pessoa_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'transit_time' => 10, 'free_time' => 7, 'armador_id' => 1, 'incoterm_id' => 1, 'validade_max' => '2026-04-01']);
        DB::table('embarque')->insert([
            ['embarque_id' => 2, 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'cotacao_impo_id' => 3, 'cotacao_expo_id' => null],
            ['embarque_id' => 3, 'impor_expor' => 'i', 'tipo_embarque' => 'a', 'cotacao_impo_id' => null, 'cotacao_expo_id' => null],
        ]);
        DB::table('capa_processo')->insert([['capa_process_id' => 98, 'embarque_id' => 2, 'moeda_id' => 1, 'capa_processo_arquivo_id' => 1], ['capa_process_id' => 99, 'embarque_id' => 3, 'moeda_id' => 1, 'capa_processo_arquivo_id' => 1]]);
        DB::table('resumo_resultado')->insert([['capa_processo_id' => 98, 'embarque_id' => 2, 'impo_expo' => 'i', 'tipo_embarque' => 'm', 'usd' => 0, 'rs' => 1, 'euro' => 0], ['capa_processo_id' => 99, 'embarque_id' => 3, 'impo_expo' => 'i', 'tipo_embarque' => 'a', 'usd' => 0, 'rs' => 1, 'euro' => 0]]);

        $lines = app(CoverResultReport::class)(['folder_id' => 1])['lines'];

        // manual (sem reserva), importação marítima, importação aérea sem cotação, exportação montada
        $this->assertSame(['', 'IMP-3', 'N/D', 'AS0005SSZHAM/2026'], collect($lines)->pluck('row.quotation')->all());
    }

    public function test_exports_the_list_with_totals_to_excel(): void
    {
        $this->summaries();

        $response = app(ExportCoverResultReport::class)(app(CoverResultReport::class)(['folder_id' => 1], ['cliente']));

        ob_start();
        $response->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, ob_get_clean());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertSame('Cliente', $sheet->getCell('F1')->getValue());
        $this->assertSame('TOTAL Cliente Manual', $sheet->getCell('A3')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('A6')->getValue());
        $this->assertEquals(100, $sheet->getCell('V6')->getValue());
        $this->assertEquals(1310, $sheet->getCell('V7')->getValue());
    }

    /** Capa normal (exportação, com demurrage, custo financeiro) e capa manual de courier (sem Impo/Expo), ambas na caixa 1. */
    private function summaries(): void
    {
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Cliente SA'], ['pessoa_id' => 2, 'nome' => 'Cliente Manual']]);
        DB::table('sedes')->insert(['id' => 1, 'nome' => 'Porto Alegre']);
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'BKG1', 'house_bl' => 'ABC1', 'cliente_id' => 1, 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'cotacao_expo_id' => 5]);
        DB::table('fin_expenses')->insert([['id' => 1, 'name' => 'Demurrage', 'operation_type' => 'e'], ['id' => 2, 'name' => 'Courier DHL', 'operation_type' => 'i']]);
        DB::table('regra_despesa_cobranca_item')->insert(['despesa_id' => 1, 'regra_despesa_cobranca_id' => 5]);
        DB::table('fin_expenses_values')->insert(['id' => 1, 'ref_expense' => 1]);

        $cover = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 2, 'numero' => 1, 'receitas' => 100, 'comissoes' => 20, 'valor_cotacao' => 5, 'custo_financeiro' => -12, 'capa_processo_arquivo_id' => 1]);
        DB::table('capa_processo_despesa_item')->insert(['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => 1, 'nome' => 'Demurrage', 'valor' => 10]);
        $manual = CapaProcessoManual::create(['moeda_id' => 1, 'numero' => 1, 'cliente_id' => 2, 'ref_expense' => 2, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'moeda_usd_eur' => 3, 'valor_usd_eur' => 10, 'valor_cotacao' => 6, 'capa_processo_arquivo_id' => 1]);

        DB::table('resumo_resultado')->insert(
            ['capa_processo_id' => $cover->getKey(), 'numero_capa' => 'AGOSTO/2026', 'booking' => 'BKG1', 'house_bl' => 'ABC1', 'cliente' => 'Cliente SA', 'cliente_id' => 1, 'impo_expo' => 'e', 'tipo_embarque' => 'm', 'centro_custo_id' => 1, 'embarque_id' => 1, 'usd' => 100, 'rs' => 600, 'euro' => 0, 'data_pagamento' => '2026-08-05'],
        );
        DB::table('resumo_resultado')->insert(
            ['capa_processo_manual_id' => $manual->getKey(), 'numero_capa' => 'AGOSTO/2026', 'booking' => '1', 'cliente' => 'Cliente Manual', 'cliente_id' => 2, 'usd' => 0, 'rs' => 50, 'euro' => 10, 'data_pagamento' => '2026-08-20'],
        );
        DB::table('capa_processo_arquivo')->insert(['capa_processo_arquivo_id' => 1, 'nome' => 'AGOSTO/2026']);
    }
}
