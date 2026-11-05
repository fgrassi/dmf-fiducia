<?php

namespace Tests\Feature\Filament;

use App\Filament\Livewire\FasaSectionTable;
use App\Filament\Pages\FasaProcessSheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class FasaProcessSheetTest extends TestCase
{
    use LegacySchema;

    public function test_only_financial_opens_the_page(): void
    {
        $this->actingAs($this->user('manager'))->get(FasaProcessSheet::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(FasaProcessSheet::getUrl())->assertOk();
    }

    public function test_mounts_one_table_per_section_and_exports_the_filtered_sheet(): void
    {
        $this->actingAs($this->user('financial'));
        $this->charge(1, type: 1, total: 1234.5);

        Livewire::test(FasaProcessSheet::class)
            ->assertSeeLivewire(FasaSectionTable::class)
            ->fillForm(['grupo_empresa_id' => null, 'venc_ini' => null, 'cliente' => 'Faros'])
            ->call('filter')
            ->callAction('export')
            ->assertFileDownloaded();
    }

    public function test_section_table_lists_only_its_rows_with_total_and_search(): void
    {
        $this->actingAs($this->user('financial'));
        $this->charge(1, type: 1, total: 1234.5);
        $this->charge(2, type: 2, total: 10);

        Livewire::test(FasaSectionTable::class, ['section' => 'embarques', 'sheetFilters' => ['cliente' => 'Faros']])
            ->assertCanSeeTableRecords([1])
            ->assertCanNotSeeTableRecords([2])
            ->assertSee(['EMBARQUES SEM BOLETO', 'TOTAL EMBARQUES SEM BOLETO: R$ 1.234,50', 'BKG-10'])
            ->assertTableColumnVisible('valor')
            ->assertTableColumnHidden('taxa')
            ->searchTable('inexistente')
            ->assertCanNotSeeTableRecords([1]);
    }

    private function charge(int $id, int $type, float $total): void
    {
        DB::table('pessoa')->insertOrIgnore(['pessoa_id' => 1, 'nome' => 'Faros Matriz']);
        DB::table('embarque')->insertOrIgnore(['embarque_id' => 10, 'booking' => 'BKG-10', 'cliente_id' => 1]);
        DB::table('fin_charges')->insert(['id' => $id, 'ref_type' => $type, 'ref_booking' => 10, 'total_value' => $total, 'created_at' => now()->toDateTimeString()]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
