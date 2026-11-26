<?php

namespace Tests\Feature\Actions\Reports;

use App\Actions\Reports\CountryMovementReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CountryMovementReportTest extends TestCase
{
    use LegacySchema;

    private const PERIOD = ['data_ini' => '2026-11-01', 'data_fim' => '2026-11-30'];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('paises')->insert([['paises_id' => 1, 'nome' => 'Brasil', 'uncode' => 'BR'], ['paises_id' => 2, 'nome' => 'China', 'uncode' => 'CN'], ['paises_id' => 3, 'nome' => 'Chile', 'uncode' => null]]);
        DB::table('porto')->insert([['porto_id' => 10, 'nome' => 'Santos', 'ref_pais' => 1], ['porto_id' => 20, 'nome' => 'Shanghai', 'ref_pais' => 2], ['porto_id' => 30, 'nome' => 'Valparaiso', 'ref_pais' => 3]]);
    }

    public function test_counts_shipments_and_containers_by_the_origin_country_for_imports_and_the_destination_for_exports(): void
    {
        $this->booking('i', 20, 10, 2, '2026-11-05');
        $this->booking('i', 20, 10, 3, '2026-11-06');
        $this->booking('e', 10, 30, 5, '2026-11-07');

        $report = app(CountryMovementReport::class)(self::PERIOD);

        $this->assertSame(['China (CN)', 'Chile'], array_column($report['rows'], 'country'));
        $this->assertSame([2, 1], array_column($report['rows'], 'shipments'));
        $this->assertSame([5, 5], array_column($report['rows'], 'containers'));
        $this->assertSame([3, 10], [$report['shipments'], $report['containers']]);
        $this->assertEqualsWithDelta(66.67, $report['rows'][0]['shipmentsPercent'], 0.01);
        $this->assertEqualsWithDelta(50.0, $report['rows'][0]['containersPercent'], 0.01);
    }

    public function test_leaves_out_cancelled_and_out_of_period_and_filters_by_type_client_country_and_confirmation(): void
    {
        $inside = $this->booking('e', 10, 20, 4, '2026-11-10', client: 7);
        $this->booking('e', 10, 20, 9, '2026-11-10', status: 'c');
        $this->booking('e', 10, 20, 9, '2026-12-01');
        $this->booking('i', 20, 10, 1, '2026-11-10', client: 8);
        DB::table('departure_confirmation')->insert(['ref_embarque' => $inside, 'created_by' => 1]);

        $count = fn (array $filters) => app(CountryMovementReport::class)(self::PERIOD + $filters)['shipments'];

        $this->assertSame(2, $count([]));
        $this->assertSame(1, $count(['impor_expor' => 'e']));
        $this->assertSame(1, $count(['pessoa_id' => 8]));
        $this->assertSame(1, $count(['pais_id' => 2, 'impor_expor' => 'e']));
        $this->assertSame(1, $count(['embarque_confirmado' => true]));
        $this->assertSame(0, $count(['porto_origem_id' => 20, 'impor_expor' => 'e']));
    }

    private function booking(string $type, int $origin, int $destination, int $containers, string $etd, string $status = 'n', int $client = 1): int
    {
        $id = DB::table('embarque')->insertGetId(['booking' => 'B'.random_int(1000, 9999), 'impor_expor' => $type, 'porto_origem_id' => $origin, 'porto_destino_id' => $destination, 'saida_prevista' => $etd, 'finalizado' => $status, 'cliente_id' => $client]);
        DB::table('mercadoria')->insert(['embarque_id' => $id, 'quantidade' => $containers]);

        return $id;
    }
}
