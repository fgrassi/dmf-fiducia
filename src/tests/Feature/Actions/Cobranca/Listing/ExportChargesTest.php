<?php

namespace Tests\Feature\Actions\Cobranca\Listing;

use App\Actions\Cobranca\Listing\BuildChargeListQuery;
use App\Actions\Cobranca\Listing\ExportCharges;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExportChargesTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-27 09:30');
        DB::table('fin_charges_types')->insert(['id' => 1, 'name' => 'Normal']);
    }

    /** @return array<string, list<list<mixed>>> abas do XLSX exportado */
    private function export(array $filters = []): array
    {
        ob_start();
        app(ExportCharges::class)($filters)->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, ob_get_clean());

        $book = IOFactory::load($path);
        unlink($path);

        return collect($book->getSheetNames())->mapWithKeys(fn ($n) => [$n => $book->getSheetByName($n)->toArray(null, true, false)])->all();
    }

    public function test_splits_open_and_settled_charges_in_two_sheets_with_total_items_plus_iof(): void
    {
        $open = $this->charge(['valor_iof' => 10, 'due_date' => '2026-10-30']);
        $this->item($open, 90, stale: 90);
        $paid = $this->charge(['status' => 1, 'payment_date' => '2026-10-25'], ['embarque_id' => 11]);
        $this->item($paid, 40, stale: 40);

        $sheets = $this->export();

        $this->assertSame(['Abertos', 'Pagos'], array_keys($sheets));
        $this->assertSame('Num. Cobrança', $sheets['Abertos'][0][0]);
        $this->assertCount(2, $sheets['Abertos']);
        $this->assertSame([$open->id, 'Normal', 'BKG10', 100.0, 'Aberto'], [$sheets['Abertos'][1][0], $sheets['Abertos'][1][1], $sheets['Abertos'][1][2], (float) $sheets['Abertos'][1][6], $sheets['Abertos'][1][10]]);
        $this->assertSame([$paid->id, 40.0], [$sheets['Pagos'][1][0], (float) $sheets['Pagos'][1][6]]);
    }

    public function test_applies_the_list_filters_to_both_sheets(): void
    {
        $this->item($this->charge(), 10, stale: 10);
        $other = $this->charge(['status' => 1], ['embarque_id' => 11]);
        $this->item($other, 20, stale: 20);

        $sheets = $this->export(['booking' => 'BKG11']);

        $this->assertCount(1, $sheets['Abertos']);
        $this->assertSame($other->id, $sheets['Pagos'][1][0]);
    }

    public function test_list_filters_by_invoice_note_number_nfse_status_and_total(): void
    {
        $a = $this->charge(['total_value' => 100]);
        $b = $this->charge(['total_value' => 1234.56], ['embarque_id' => 11]);
        DB::table('fin_charges_arquivo_nota')->insert(['cobranca_id' => $a->id, 'arquivo' => 'nf.pdf', 'numero_nota_fiscal' => '777']);
        DB::table('fin_charges_nfse')->insert(['ref_charge' => $b->id, 'id_externo' => 'x', 'status' => 'rejeitada']);
        $ids = fn (array $f) => app(BuildChargeListQuery::class)->applyFilters(FinCharges::query(), $f)->pluck('id')->all();

        $this->assertSame([$a->id], $ids(['num_nota_fiscal' => '777']));
        $this->assertSame([$b->id], $ids(['valor_total' => '1.234,56']));
        $this->assertSame([$b->id], $ids(['status_nfse' => 'rejeitada']));
        $this->assertSame([$a->id], $ids(['status_nfse' => 'nao_gerada']));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids([]));
    }
}
