<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\ExportNotesReport;
use App\Filament\Pages\NotesReport;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NotesReportTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    /** @return list<string> títulos das abas do arquivo gerado */
    private function sheets(int $report, int $month = 10): array
    {
        $response = app(ExportNotesReport::class)($report, $month, 2026);
        $this->assertNotNull($response);

        ob_start();
        $response->sendContent();
        $file = tempnam(sys_get_temp_dir(), 'nr').'.xls';
        file_put_contents($file, ob_get_clean());
        $names = IOFactory::load($file)->getSheetNames();
        unlink($file);

        return $names;
    }

    public function test_only_financial_opens_the_page(): void
    {
        $this->actingAs($this->user('manager'))->get(NotesReport::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(NotesReport::getUrl())->assertOk();
    }

    public function test_faros_sheets_are_the_paid_charges_of_group_clients_in_the_payment_month(): void
    {
        DB::table('grupo_empresa_membro')->insert(['grupo_empresa_id' => 1, 'pessoa_id' => 7, 'created_by' => 1]);
        $paid = ['status' => 1, 'payment_date' => '2026-10-15'];
        $this->charge($paid, ['embarque_id' => 1, 'booking' => 'IN-1', 'cliente_id' => 7]);
        $this->charge([...$paid, 'payment_date' => '2026-09-15'], ['embarque_id' => 2, 'booking' => 'OUT-MONTH', 'cliente_id' => 7]);
        $this->charge($paid, ['embarque_id' => 3, 'booking' => 'OUT-CLIENT', 'cliente_id' => 8]);
        $this->charge([...$paid, 'status' => 0], ['embarque_id' => 4, 'booking' => 'OUT-OPEN', 'cliente_id' => 7]);

        $this->assertSame(['IN-1'], $this->sheets(ExportNotesReport::FAROS));
    }

    public function test_jr_sheets_follow_the_effective_departure_month(): void
    {
        $paid = ['status' => 1, 'payment_date' => '2026-01-15'];
        $this->charge($paid, ['embarque_id' => 1, 'booking' => 'JR/1', 'cliente_id' => 2516, 'saida_efetiva' => '2026-10-03']);
        $this->charge($paid, ['embarque_id' => 2, 'booking' => 'OUT-MONTH', 'cliente_id' => 2516, 'saida_efetiva' => '2026-11-03']);

        $this->assertSame(['JR-1'], $this->sheets(ExportNotesReport::JR));
    }

    public function test_empty_period_warns_instead_of_downloading(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(NotesReport::class)
            ->fillForm(['month' => 10, 'year' => 2026])
            ->callAction('faros')
            ->assertNotified('Não há recibos para o período selecionado!');
    }
}
