<?php

namespace Tests\Feature\Actions\Reports;

use App\Actions\Reports\ExportQuotationHistoryReport;
use App\Actions\Reports\QuotationHistoryChartData;
use App\Actions\Reports\QuotationHistoryReport;
use DomainException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;
use ZipArchive;

class QuotationHistoryReportTest extends TestCase
{
    use LegacySchema;

    private const FILTERS = ['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31', 'tipo' => 'impo', 'modal' => 'maritimo', 'pais_origem_ids' => [1], 'despesa_ids' => [1]];

    public function test_summarises_the_sale_values_per_group_and_currency_and_ignores_zero_values_in_the_summary(): void
    {
        $this->quotations();

        $report = app(QuotationHistoryReport::class)(self::FILTERS + ['agrupamento' => ['mes', 'equipamento']]);

        $this->assertSame(4, $report['registros']);
        $this->assertSame([
            [['10/2026', "40' HC"], 'EUR', 1, 50.0, 50.0, 50.0],
            [['10/2026', "40' HC"], 'USD', 2, 100.0, 300.0, 200.0],
        ], array_map(fn (array $row) => [$row['dimensoes'], $row['moeda'], $row['quantidade'], $row['minimo'], $row['maximo'], $row['media']], $report['resumo']));
        $this->assertSame('Santos (BRSSZ) / Brasil', $report['rows'][0]['origem']);
        $this->assertSame('Cliente A (123)', $report['rows'][0]['cliente']);
    }

    public function test_filters_by_validity_client_cargo_type_and_group_of_expenses(): void
    {
        $this->quotations();
        $run = fn (array $filters) => app(QuotationHistoryReport::class)($filters + self::FILTERS)['registros'];

        $this->assertSame(0, $run(['tipo_data' => 'validade']));
        $this->assertSame(4, $run(['tipo_data' => 'validade', 'data_ini' => '2026-12-01', 'data_fim' => '2026-12-31']));
        $this->assertSame(0, $run(['cliente_id' => 99]));
        $this->assertSame(0, $run(['tipo_carga' => 'lcl']));
        $this->assertSame(4, $run(['tipo_carga' => 'fcl']));
        $this->assertSame(0, $run(['despesa_ids' => [], 'regra_ids' => [7]]));

        DB::table('regra_despesa_cobranca_item')->insert(['despesa_id' => 1, 'regra_despesa_cobranca_id' => 7]);
        $this->assertSame(4, $run(['despesa_ids' => [], 'regra_ids' => [7]]));
    }

    public function test_reads_the_expo_and_air_import_quotations(): void
    {
        $this->quotations();
        DB::table('cotacao_expo')->insert(['cotacao_expo_id' => 5, 'pessoa_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 1, 'transit_time' => 1, 'armador_id' => 1, 'validade' => '2026-12-31', 'created_at' => '2026-10-10 09:00:00']);
        DB::table('cotacao_expo_item')->insert(['cotacao_expo_id' => 5, 'despesa_id' => 1, 'moeda_compra_id' => 1, 'moeda_venda_id' => 1, 'valor_venda' => 70, 'tipo_container_id' => 1]);
        DB::table('cotacao_impo_aereo')->insert(['cotacao_impo_aereo_id' => 6, 'pessoa_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 1, 'transit_time' => 1, 'frequencia' => 'x', 'cia_aerea_id' => 1, 'validade' => '2026-12-31', 'incoterm_id' => 1, 'created_at' => '2026-10-10 09:00:00']);
        DB::table('cotacao_impo_aereo_item')->insert(['cotacao_impo_aereo_id' => 6, 'despesa_id' => 1, 'moeda_compra_id' => 1, 'moeda_venda_id' => 1, 'valor_venda' => 9, 'tipo_container_id' => 1]);

        $expo = app(QuotationHistoryReport::class)(['tipo' => 'expo'] + self::FILTERS);
        $air = app(QuotationHistoryReport::class)(['modal' => 'aereo'] + self::FILTERS);

        $this->assertSame(['Expo', 1, 70.0], [$expo['tipo_label'], $expo['registros'], $expo['resumo'][0]['media']]);
        $this->assertSame(['Impo Aéreo', 1, 9.0], [$air['tipo_label'], $air['registros'], $air['resumo'][0]['media']]);
    }

    public function test_rejects_invalid_filters(): void
    {
        $run = fn (array $filters) => fn () => app(QuotationHistoryReport::class)($filters + self::FILTERS);

        $cases = [
            [['data_fim' => null], 'Informe a data inicial e a data final.'],
            [['data_ini' => '2026-11-01'], 'A data inicial deve ser anterior ou igual à data final.'],
            [['tipo' => 'expo', 'modal' => 'aereo'], 'Não há cotações aéreas de Expo. Selecione Marítimo ou altere o tipo para Impo.'],
            [['pais_origem_ids' => []], 'Informe país ou porto de origem, ou país ou porto de destino.'],
            [['despesa_ids' => []], 'Informe a despesa ou o grupo de despesas.'],
        ];

        foreach ($cases as [$filters, $message]) {
            try {
                $run($filters)();
                $this->fail("Sem erro: {$message}");
            } catch (DomainException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_excel_has_the_summary_and_optionally_the_list(): void
    {
        $this->quotations();
        $report = app(QuotationHistoryReport::class)(self::FILTERS);

        $summaryOnly = app(ExportQuotationHistoryReport::class)->build($report, false);
        $both = app(ExportQuotationHistoryReport::class)->build($report, true);

        $this->assertSame(1, $summaryOnly->getSheetCount());
        $this->assertSame(['Equipamento', 'Moeda', 'Quantidade', 'Minimo', 'Maximo', 'Media'], $both->getSheetByName('Resumo')->rangeToArray('A1:F1')[0]);
        $this->assertSame('1 - REF-1', $both->getSheetByName('Listagem')->getCell('A2')->getValue());
        $this->assertSame(5, $both->getSheetByName('Listagem')->getHighestRow());
    }

    public function test_excel_never_turns_database_text_into_formulas(): void
    {
        $this->quotations();
        DB::table('pessoa')->where('pessoa_id', 1)->update(['nome' => '=HYPERLINK("http://x","y")', 'cnpj' => null]);
        DB::table('cotacao_impo')->update(['referencia' => '=1+1']);
        $report = app(QuotationHistoryReport::class)(self::FILTERS + ['agrupamento' => ['equipamento']]);

        $list = app(ExportQuotationHistoryReport::class)->build($report, true)->getSheetByName('Listagem');

        $this->assertSame('s', $list->getCell('D2')->getDataType());
        $this->assertSame('=HYPERLINK("http://x","y")', $list->getCell('D2')->getValue());
        $this->assertSame('s', $list->getCell('A2')->getDataType());
        $this->assertSame('n', $list->getCell('J2')->getDataType());
    }

    public function test_chart_regroups_the_summary_with_a_weighted_average_and_the_group_min_and_max(): void
    {
        $this->quotations();
        $report = app(QuotationHistoryReport::class)(self::FILTERS + ['agrupamento' => ['mes', 'equipamento']]);
        $chart = fn (array $options) => app(QuotationHistoryChartData::class)($report['resumo'], $report['agrupamento'], $options);

        $this->assertSame(['type' => 'linha', 'x_axis' => ['mes', 'moeda'], 'series' => '', 'metrics' => ['media']], QuotationHistoryChartData::defaults($report['agrupamento']));
        $this->assertSame(['10/2026'], $chart(['x_axis' => ['mes']])['categories']);
        $this->assertSame([150.0], $chart(['x_axis' => ['mes']])['series'][0]['values']);
        $this->assertSame(['10/2026 | EUR', '10/2026 | USD'], $chart(['x_axis' => ['mes', 'moeda']])['categories']);
        $this->assertSame([['Quantidade', [3]], ['Mínimo', [50.0]], ['Máximo', [300.0]]], array_map(fn (array $series) => [$series['name'], $series['values']], $chart(['x_axis' => ['mes'], 'metrics' => ['quantidade', 'minimo', 'maximo']])['series']));
        $this->assertSame([['EUR', [50.0]], ['USD', [200.0]]], array_map(fn (array $series) => [$series['name'], $series['values']], $chart(['x_axis' => ['mes'], 'series' => 'moeda'])['series']));
        $this->assertSame('barra', $chart(['type' => 'barra'])['type']);
    }

    public function test_excel_with_chart_adds_the_data_sheet_and_the_drawing(): void
    {
        $this->quotations();
        $report = app(QuotationHistoryReport::class)(self::FILTERS + ['agrupamento' => ['mes']]);
        $chart = app(QuotationHistoryChartData::class)($report['resumo'], $report['agrupamento'], ['x_axis' => ['mes', 'moeda']]);

        $book = app(ExportQuotationHistoryReport::class)->build($report, false, $chart);
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($book))->setIncludeCharts(true)->save($file);
        $zip = new ZipArchive;
        $zip->open($file);

        $this->assertSame(['Categoria', 'Média'], $book->getSheetByName('Grafico')->rangeToArray('A1:B1')[0]);
        $this->assertSame('10/2026 | EUR', $book->getSheetByName('Grafico')->getCell('A2')->getValue());
        $this->assertNotFalse($zip->locateName('xl/charts/chart1.xml'));
        unlink($file);
    }

    /** Quatro itens da despesa 1 em cotações Impo de outubro: 100 e 300 USD, 50 EUR e um zero (só na lista). */
    private function quotations(): void
    {
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Brasil']);
        DB::table('porto')->insert(['porto_id' => 1, 'nome' => 'Santos', 'uncode' => 'BRSSZ', 'ref_pais' => 1]);
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente A', 'cnpj' => '123']);
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'THC']);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => "40' HC", 'tipo' => '40', 'tipo_embarque' => 'm']);
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Dólar', 'symbol' => 'USD'], ['id' => 2, 'name' => 'Euro', 'symbol' => 'EUR']]);
        DB::table('cotacao_impo')->insert(['cotacao_impo_id' => 1, 'pessoa_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 1, 'transit_time' => 1, 'free_time' => 1, 'armador_id' => 1, 'incoterm_id' => 1, 'validade_max' => '2026-12-31', 'referencia' => 'REF-1', 'created_at' => '2026-10-05 10:00:00']);

        foreach ([[100, 1], [300, 1], [50, 2], [0, 1]] as [$value, $currency]) {
            DB::table('cotacao_impo_item')->insert(['cotacao_impo_id' => 1, 'despesa_id' => 1, 'moeda_compra_id' => 1, 'moeda_venda_id' => $currency, 'valor_venda' => $value, 'tipo_container_id' => 1]);
        }
    }
}
