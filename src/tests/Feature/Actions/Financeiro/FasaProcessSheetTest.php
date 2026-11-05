<?php

namespace Tests\Feature\Actions\Financeiro;

use App\Actions\Financeiro\ExportFasaProcessSheet;
use App\Actions\Financeiro\FasaProcessSheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class FasaProcessSheetTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Faros Matriz'], ['pessoa_id' => 2, 'nome' => 'Outro Cliente'], ['pessoa_id' => 3, 'nome' => 'Shipper X']]);
        DB::table('grupo_empresa')->insert(['grupo_empresa_id' => 1, 'nome' => 'FAROS']);
        DB::table('grupo_empresa_membro')->insert(['grupo_empresa_id' => 1, 'pessoa_id' => 1, 'created_by' => 1]);
        DB::table('embarque')->insert([
            ['embarque_id' => 10, 'booking' => 'BKG-10', 'invoice' => 'INV-10', 'cliente_id' => 1, 'pessoa_id_cliente' => 3, 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'saida_efetiva' => '2026-10-01'],
            ['embarque_id' => 20, 'booking' => 'BKG-20', 'invoice' => null, 'cliente_id' => 2, 'pessoa_id_cliente' => null, 'impor_expor' => 'i', 'tipo_embarque' => 'a', 'saida_efetiva' => null],
        ]);
        DB::table('bl')->insert(['bl_id' => 5, 'dmf_ref' => 'DMF-5']);
        DB::table('fin_expenses')->insert([['id' => 1, 'name' => 'THC', 'ref_unity' => 1], ['id' => 2, 'name' => 'Detention 7 dias', 'ref_unity' => 1]]);
        DB::table('fin_expenses_values')->insert([['id' => 1, 'ref_expense' => 1, 'ref_embarque' => 10], ['id' => 2, 'ref_expense' => 2, 'ref_embarque' => 10]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_without_client_or_group_it_returns_empty_sections(): void
    {
        $this->charge(1, type: 1);

        $sections = app(FasaProcessSheet::class)([]);

        $this->assertSame(['embarques', 'taxas', 'detention'], array_keys($sections));
        $this->assertSame([[], [], []], array_column($sections, 'rows'));
    }

    public function test_splits_charges_into_sections_with_totals_and_only_for_the_group(): void
    {
        $this->charge(1, type: 1, total: 100, due: '2026-10-20', blBase: 5);
        $this->charge(2, type: 2, total: 50.5, due: '2026-11-30', items: [1]);
        $this->charge(3, type: 2, total: 20, due: '2026-11-30', items: [1, 2]);
        $this->charge(4, type: 4, total: 10, due: '2026-11-30');
        $this->charge(5, type: 1, total: 999, booking: 20);
        $this->charge(6, type: 5, total: 999);

        $sections = app(FasaProcessSheet::class)(['grupo_empresa_id' => 1]);

        $this->assertSame([1], array_column($sections['embarques']['rows'], 'id'));
        $this->assertSame([2], array_column($sections['taxas']['rows'], 'id'));
        $this->assertSame([3, 4], array_column($sections['detention']['rows'], 'id'));
        $this->assertSame([100.0, 50.5, 30.0], [$sections['embarques']['total'], $sections['taxas']['total'], $sections['detention']['total']]);

        $row = $sections['embarques']['rows'][0];
        $this->assertSame(['Faros Matriz', 'Shipper X', 'BKG-10', 'DMF-5', '01/10/2026', 'Normal', '1/2026'], [$row['empresa'], $row['shipper'], $row['booking'], $row['fatura'], $row['saida_efetiva'], $row['tipo_cobranca'], $row['num_cobranca']]);
        $this->assertSame('THC + Detention 7 dias', $sections['detention']['rows'][0]['taxa']);
    }

    public function test_situation_and_delay(): void
    {
        $this->charge(1, type: 1, due: '2026-11-01');
        $this->charge(2, type: 1, due: '2026-11-10');
        $this->charge(3, type: 1, due: '2026-10-01', status: 1, paid: '2026-10-03');
        $this->charge(4, type: 1, due: '2026-10-01', status: 1, paid: '2026-10-01');

        $rows = collect(app(FasaProcessSheet::class)(['cliente' => 'faros'])['embarques']['rows'])->keyBy('id');

        $this->assertSame(['vencido', '4 dias de atraso'], [$rows[1]['situacao'], $rows[1]['atraso']]);
        $this->assertSame(['Aberto', 'pago em dia'], [$rows[2]['situacao'], $rows[2]['atraso']]);
        $this->assertSame(['Pago', '2 dias de atraso', '03/10/2026'], [$rows[3]['situacao'], $rows[3]['atraso'], $rows[3]['data_pagamento']]);
        $this->assertSame('pago em dia', $rows[4]['atraso']);
    }

    public function test_filters_by_effective_due_date_payment_and_status_and_matches_shipper_name(): void
    {
        $this->charge(1, type: 1, due: '2026-10-10');
        $this->charge(2, type: 1, due: null, created: '2026-10-25 08:00:00');
        $this->charge(3, type: 1, due: '2026-11-20', status: 1, paid: '2026-11-02');

        $action = app(FasaProcessSheet::class);
        $ids = fn (array $filters) => array_column($action($filters + ['cliente' => 'Shipper X'])['embarques']['rows'], 'id');

        $this->assertSame([2, 1, 3], $ids([])); // sem vencimento vem primeiro, como no legado
        $this->assertSame([2, 3], $ids(['venc_ini' => '2026-10-20']));
        $this->assertSame([2, 1], $ids(['venc_fim' => '2026-10-31']));
        $this->assertSame([3], $ids(['baixa_ini' => '2026-11-01']));
        $this->assertSame([2, 1], $ids(['status' => 0]));
        $this->assertSame([], $ids(['impor_expor' => 'i']));
    }

    public function test_exports_one_sheet_per_section_with_the_total_row(): void
    {
        $this->charge(1, type: 1, total: 100.5, due: '2026-10-20');

        $response = app(ExportFasaProcessSheet::class)(app(FasaProcessSheet::class)(['grupo_empresa_id' => 1]));
        ob_start();
        $response->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'fasa');
        file_put_contents($path, ob_get_clean());
        $book = IOFactory::load($path);
        unlink($path);

        $this->assertSame(['EMBARQUES SEM BOLETO', 'TAXAS DIVERSAS', 'DETENTION'], $book->getSheetNames());
        $sheet = $book->getSheetByName('EMBARQUES SEM BOLETO');
        $this->assertSame(['Faros Matriz', 100.5, 'vencido'], [$sheet->getCell('A2')->getValue(), $sheet->getCell('E2')->getValue(), $sheet->getCell('H2')->getValue()]);
        $this->assertSame(['TOTAL EMBARQUES SEM BOLETO', 100.5], [$sheet->getCell('A3')->getValue(), $sheet->getCell('E3')->getValue()]);
    }

    /** @param  list<int>  $items  fin_expenses_values */
    private function charge(int $id, int $type, float $total = 10, ?string $due = '2026-11-30', int $booking = 10, int $status = 0, ?string $paid = null, ?string $created = '2026-10-01 09:00:00', int $blBase = 0, array $items = []): void
    {
        DB::table('fin_charges')->insert(['id' => $id, 'ref_type' => $type, 'ref_booking' => $booking, 'ref_bl' => $blBase ?: null, 'status' => $status, 'total_value' => $total, 'due_date' => $due, 'payment_date' => $paid, 'created_at' => $created]);

        foreach ($items as $value) {
            DB::table('fin_charges_items')->insert(['ref_charge' => $id, 'ref_expense' => $value, 'ref_currency' => 1, 'created_at' => '2026-10-01']);
        }
    }
}
