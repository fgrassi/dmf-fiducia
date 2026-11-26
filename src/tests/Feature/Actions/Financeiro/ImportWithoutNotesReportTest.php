<?php

namespace Tests\Feature\Actions\Financeiro;

use App\Actions\Financeiro\ImportWithoutNotesReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ImportWithoutNotesReportTest extends TestCase
{
    use LegacySchema;

    public function test_on_the_last_day_lists_confirmed_imports_of_the_month_without_notes(): void
    {
        $this->travelTo('2026-03-31 08:00');
        $this->booking(1, '2026-03-01');
        $this->booking(2, '2026-03-31');
        $this->booking(3, '2026-02-28');
        $this->booking(4, '2026-03-10', operation: 'e');
        $this->booking(5, '2026-03-10', confirmed: false);
        $this->booking(6, '2026-03-10');
        $this->booking(7, '2026-03-10');
        $this->booking(8, '2026-03-10');
        $this->booking(9, '2026-03-10');
        $this->booking(10, '2026-03-10');
        $note = ['ref_agente' => 1, 'created_at' => '2026-03-11', 'created_by' => 1];
        DB::table('fin_debit_notes')->insert(['ref_embarque' => 6, ...$note]);
        DB::table('fin_credit_notes')->insert(['ref_embarque' => 7, ...$note]);
        DB::table('emb_embarque_files')->insert([
            ['ref_embarque' => 8, 'ref_file' => 1, 'ref_file_type' => 4],
            ['ref_embarque' => 9, 'ref_file' => 2, 'ref_file_type' => 5],
            ['ref_embarque' => 10, 'ref_file' => 3, 'ref_file_type' => 24],
        ]);

        $listed = app(ImportWithoutNotesReport::class)();

        $this->assertSame(3, $listed);
        $this->assertSame(['DMF RELATÓRIO DE NOTAS IMPORTACÃO: MARÇO/2026'], $this->sentSubjects()->all());
        $this->assertSame(['it@dmflog.com.br', 'financeiro@dmflog.com.br'], $this->sentRecipients());
        $this->assertStringContainsString('<ul><li>BKG1</li><li>BKG2</li><li>BKG10</li></ul>', $this->sentHtml());
    }

    public function test_sends_nothing_when_every_import_has_notes(): void
    {
        $this->travelTo('2026-03-31 08:00');

        $this->assertSame(0, app(ImportWithoutNotesReport::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }

    public function test_sends_nothing_before_the_last_day_of_the_month(): void
    {
        $this->travelTo('2026-03-30 08:00');
        $this->booking(1, '2026-03-10');

        $this->assertSame(0, app(ImportWithoutNotesReport::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }

    private function booking(int $id, string $arrival, string $operation = 'i', bool $confirmed = true): void
    {
        DB::table('embarque')->insert(['embarque_id' => $id, 'booking' => "BKG{$id}", 'impor_expor' => $operation, 'chegada_efetiva' => $arrival]);

        if ($confirmed) {
            DB::table('arrival_confirmation')->insert(['ref_embarque' => $id, 'created_by' => 1]);
        }
    }
}
