<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AccountingCovers\AccountingCoverResource;
use App\Filament\Resources\ManualAccountingCovers\ManualAccountingCoverResource;
use App\Filament\Resources\ManualAccountingCovers\Pages\ListManualAccountingCovers;
use App\Models\CapaProcessoArquivo;
use App\Models\CapaProcessoManual;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ManualAccountingCoverResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_financial_or_director_credential(): void
    {
        $this->actingAs($this->user('booking'))->get(ManualAccountingCoverResource::getUrl())->assertForbidden();
    }

    public function test_both_lists_show_the_tabs_that_link_to_each_other(): void
    {
        $this->actingAs($this->user('financial'));

        foreach ([AccountingCoverResource::getUrl(), ManualAccountingCoverResource::getUrl()] as $url) {
            $this->get($url)->assertOk()->assertSee(['Capas normais', 'Capas manuais'])
                ->assertSee(AccountingCoverResource::getUrl(), false)->assertSee(ManualAccountingCoverResource::getUrl(), false);
        }
    }

    public function test_lists_unarchived_manual_covers_by_default_with_their_amounts(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']]);
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente SA']);
        DB::table('fin_expenses')->insert(['id' => 9, 'name' => 'Armazenagem']);
        $folder = CapaProcessoArquivo::create(['nome' => 'AGOSTO/2026', 'mes' => 8, 'ano' => 2026, 'status' => 0]);
        $open = $this->manual(['numero' => 7, 'cliente_id' => 5, 'nome_cliente' => 'Fulano', 'ref_expense' => 9, 'moeda_id' => 2, 'valor_cotacao' => 5, 'ref_currency_sr' => 2, 'buying_rate' => 100, 'selling_rate' => 250.5, 'payment_date' => '2026-09-03']);
        $archived = $this->manual(['numero' => 6, 'capa_processo_arquivo_id' => $folder->getKey()]);

        Livewire::test(ListManualAccountingCovers::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$archived])
            ->assertSee(['Cliente SA', 'Fulano', '5,0000', 'Armazenagem', 'R$', 'US$', '100,00', '250,50', '03/09/2026', 'Não Arquivado'])
            ->filterTable('display', 'archived')
            ->assertCanSeeTableRecords([$archived])->assertCanNotSeeTableRecords([$open])
            ->filterTable('display', 'all')
            ->assertCanSeeTableRecords([$open, $archived]);
    }

    public function test_filters_by_number_client_name_expense_folder_and_payment_period(): void
    {
        $this->actingAs($this->user('financial'));
        $folder = CapaProcessoArquivo::create(['nome' => 'AGOSTO/2026', 'mes' => 8, 'ano' => 2026, 'status' => 0]);
        $first = $this->manual(['numero' => 1, 'cliente_id' => 5, 'nome_cliente' => 'Alfa', 'ref_expense' => 9, 'payment_date' => '2026-09-03']);
        $second = $this->manual(['numero' => 2, 'cliente_id' => 6, 'nome_cliente' => 'Beta', 'ref_expense' => 8, 'payment_date' => '2026-10-20']);
        $archived = $this->manual(['numero' => 3, 'capa_processo_arquivo_id' => $folder->getKey()]);

        Livewire::test(ListManualAccountingCovers::class)
            ->filterTable('numero', ['numero' => 2])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('client', 5)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->resetTableFilters()
            ->filterTable('client_name', ['client_name' => 'bet'])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('expense', 9)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->resetTableFilters()
            ->filterTable('payment', ['from' => '2026-10-01', 'until' => '2026-10-31'])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('folder', $folder->getKey())->assertCanSeeTableRecords([$archived])->assertCanNotSeeTableRecords([$first, $second]);
    }

    public function test_archives_selected_manual_covers_into_an_open_folder(): void
    {
        $this->actingAs($this->user('financial'));
        $open = CapaProcessoArquivo::create(['nome' => 'SETEMBRO/2026', 'mes' => 9, 'ano' => 2026, 'status' => 1]);
        $first = $this->manual(['numero' => 1]);
        $second = $this->manual(['numero' => 2]);

        Livewire::test(ListManualAccountingCovers::class)
            ->selectTableRecords([$first->getKey()])
            ->callAction(TestAction::make('archive')->table()->bulk(), ['folder' => $open->getKey()])
            ->assertHasNoFormErrors();

        $this->assertSame($open->getKey(), $first->fresh()->capa_processo_arquivo_id);
        $this->assertNull($second->fresh()->capa_processo_arquivo_id);
    }

    public function test_creates_edits_and_deletes_a_manual_cover(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']]);
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente SA']);
        DB::table('fin_expenses')->insert(['id' => 9, 'name' => 'Armazenagem']);

        $page = Livewire::test(ListManualAccountingCovers::class)
            ->callAction('create', ['cliente_id' => 5, 'nome_cliente' => 'Fulano', 'moeda_id' => 2, 'valor_cotacao' => 5, 'payment_date' => '2026-10-03', 'ref_expense' => 9, 'ref_currency_br' => 1, 'buying_rate' => 100, 'ref_currency_sr' => 2, 'selling_rate' => 250])
            ->assertHasNoFormErrors();

        $cover = CapaProcessoManual::firstOrFail();
        $this->assertEquals([1, 5, 250], [$cover->numero, $cover->cliente_id, $cover->selling_rate]);

        $page->callAction(TestAction::make('edit')->table($cover), ['cliente_id' => 5, 'moeda_id' => 2, 'ref_currency_br' => 1, 'ref_currency_sr' => 2, 'selling_rate' => 300])
            ->assertHasNoFormErrors();
        $this->assertEquals(300, $cover->fresh()->selling_rate);

        $page->callAction(TestAction::make('delete')->table($cover));
        $this->assertModelMissing($cover);
    }

    public function test_downloads_the_manual_cover_pdf_from_the_list(): void
    {
        $this->actingAs($this->user('financial'));
        $cover = $this->manual(['numero' => 12]);

        Livewire::test(ListManualAccountingCovers::class)
            ->callAction(TestAction::make('pdf')->table($cover))
            ->assertFileDownloaded('capa-manual-12.pdf');
    }

    public function test_requires_client_currency_and_item_currencies(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ListManualAccountingCovers::class)
            ->callAction('create', [])
            ->assertHasFormErrors(['cliente_id' => 'required', 'moeda_id' => 'required', 'ref_currency_br' => 'required', 'ref_currency_sr' => 'required']);
    }

    /** @param array<string, mixed> $attributes */
    private function manual(array $attributes = []): CapaProcessoManual
    {
        return CapaProcessoManual::create($attributes + ['moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'numero' => 1]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
