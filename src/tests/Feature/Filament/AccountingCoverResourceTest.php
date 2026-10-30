<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AccountingCovers\AccountingCoverResource;
use App\Filament\Resources\AccountingCovers\Pages\ListAccountingCovers;
use App\Filament\Resources\CoverFolders\CoverFolderResource;
use App\Filament\Resources\CoverFolders\Pages\ManageCoverFolders;
use App\Models\CapaProcesso;
use App\Models\CapaProcessoArquivo;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AccountingCoverResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_financial_or_director_credential(): void
    {
        $this->actingAs($this->user('booking'))->get(AccountingCoverResource::getUrl())->assertForbidden();
        $this->actingAs($this->user('booking', 'outro'))->get(CoverFolderResource::getUrl())->assertForbidden();
    }

    public function test_allows_director(): void
    {
        $this->actingAs($this->user('director'))->get(AccountingCoverResource::getUrl())->assertOk();
    }

    public function test_lists_unarchived_covers_by_default_with_amounts_and_results(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10']);
        $folder = CapaProcessoArquivo::create(['nome' => 'AGOSTO/2026', 'mes' => 8, 'ano' => 2026, 'status' => 0]);
        $open = $this->cover(['numero' => 7, 'embarque_id' => 10, 'moeda_id' => 2, 'receitas' => 100, 'comissoes' => 0, 'valor_cotacao' => 5, 'custo_financeiro' => 12.5]);
        $archived = $this->cover(['numero' => 6, 'capa_processo_arquivo_id' => $folder->getKey()]);
        DB::table('capa_processo_receita_item')->insert(['capa_processo_id' => $open->getKey(), 'fin_expenses_values_id' => 1, 'nome' => 'THC', 'valor' => 1000]);

        Livewire::test(ListAccountingCovers::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$archived])
            ->assertSee(['BKG10', 'Dólar (US$)', 'US$ 12,50', 'US$ 100,00', 'R$ 5,0000', 'R$ 1.487,50', 'Não Arquivado'])
            ->filterTable('display', 'archived')
            ->assertCanSeeTableRecords([$archived])->assertCanNotSeeTableRecords([$open])
            ->assertSee('AGOSTO/2026')
            ->filterTable('display', 'all')
            ->assertCanSeeTableRecords([$open, $archived]);
    }

    public function test_recent_orders_by_creation_instead_of_number(): void
    {
        $this->actingAs($this->user('financial'));
        $older = $this->cover(['numero' => 9]);
        $newer = $this->cover(['numero' => 1]);

        Livewire::test(ListAccountingCovers::class)
            ->assertCanSeeTableRecords([$older, $newer], inOrder: true)
            ->filterTable('display', 'recent')
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_searching_by_number_booking_or_folder_ignores_the_display_filter(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert([['embarque_id' => 10, 'booking' => 'BKG10'], ['embarque_id' => 11, 'booking' => 'XYZ11']]);
        $folder = CapaProcessoArquivo::create(['nome' => 'AGOSTO/2026', 'mes' => 8, 'ano' => 2026, 'status' => 0]);
        $archived = $this->cover(['numero' => 5, 'embarque_id' => 10, 'capa_processo_arquivo_id' => $folder->getKey()]);
        $other = $this->cover(['numero' => 6, 'embarque_id' => 11, 'capa_processo_arquivo_id' => $folder->getKey()]);

        Livewire::test(ListAccountingCovers::class)
            ->assertCanNotSeeTableRecords([$archived, $other])
            ->filterTable('numero', ['numero' => 5])
            ->assertCanSeeTableRecords([$archived])->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->filterTable('booking', ['booking' => 'XYZ'])
            ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$archived])
            ->resetTableFilters()
            ->filterTable('folder', $folder->getKey())
            ->assertCanSeeTableRecords([$archived, $other]);
    }

    public function test_filters_by_ports_client_goods_and_operation(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert([
            ['embarque_id' => 10, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'cliente_id' => 5, 'impor_expor' => 'i'],
            ['embarque_id' => 11, 'porto_origem_id' => 3, 'porto_destino_id' => 4, 'cliente_id' => 6, 'impor_expor' => 'e'],
        ]);
        DB::table('mercadoria')->insert([['embarque_id' => 10, 'nome' => 'Café verde'], ['embarque_id' => 11, 'nome' => 'Soja']]);
        $first = $this->cover(['embarque_id' => 10]);
        $second = $this->cover(['embarque_id' => 11]);

        Livewire::test(ListAccountingCovers::class)
            ->filterTable('origin_port', 1)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->resetTableFilters()
            ->filterTable('destination_port', 4)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('client', 5)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->resetTableFilters()
            ->filterTable('goods', ['goods' => 'soja'])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('operation', 'e')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
    }

    public function test_archives_selected_covers_into_an_open_folder(): void
    {
        $this->actingAs($this->user('financial'));
        $open = CapaProcessoArquivo::create(['nome' => 'SETEMBRO/2026', 'mes' => 9, 'ano' => 2026, 'status' => 1]);
        CapaProcessoArquivo::create(['nome' => 'AGOSTO/2026', 'mes' => 8, 'ano' => 2026, 'status' => 0]);
        $first = $this->cover(['numero' => 1]);
        $second = $this->cover(['numero' => 2]);

        Livewire::test(ListAccountingCovers::class)
            ->selectTableRecords([$first->getKey()])
            ->callAction(TestAction::make('archive')->table()->bulk(), ['folder' => $open->getKey()])
            ->assertHasNoFormErrors();

        $this->assertSame($open->getKey(), $first->fresh()->capa_processo_arquivo_id);
        $this->assertNull($second->fresh()->capa_processo_arquivo_id);
    }

    public function test_new_folder_action_creates_next_folder_and_reports_the_block(): void
    {
        $this->actingAs($this->user('financial'));
        CapaProcessoArquivo::create(['nome' => 'SETEMBRO/2026', 'mes' => 9, 'ano' => 2026, 'status' => 1]);
        $this->cover();

        Livewire::test(ListAccountingCovers::class)
            ->callAction('newFolder')
            ->assertNotified('Não é possível criar uma Nova Caixa. Existem capas não arquivadas!');
        $this->assertDatabaseMissing('capa_processo_arquivo', ['nome' => 'OUTUBRO/2026']);

        CapaProcesso::query()->update(['capa_processo_arquivo_id' => 1]);

        Livewire::test(ListAccountingCovers::class)
            ->callAction('newFolder')
            ->assertNotified('Caixa OUTUBRO/2026 criada.');
    }

    public function test_folders_page_lists_newest_first_and_edits_month_year_and_status_keeping_the_name(): void
    {
        $this->actingAs($this->user('financial'));
        $old = CapaProcessoArquivo::create(['nome' => 'JULHO/2026', 'mes' => 7, 'ano' => 2026, 'status' => 0]);
        $new = CapaProcessoArquivo::create(['nome' => 'AGOSTO/2026', 'mes' => 8, 'ano' => 2026, 'status' => 1]);

        Livewire::test(ManageCoverFolders::class)
            ->assertCanSeeTableRecords([$new, $old], inOrder: true)
            ->callAction(TestAction::make('edit')->table($old), ['mes' => 6, 'ano' => 2025, 'status' => 1])
            ->assertHasNoFormErrors();

        $this->assertSame(['JULHO/2026', 6, 2025, 1], [$old->fresh()->nome, $old->fresh()->mes, $old->fresh()->ano, (int) $old->fresh()->status]);
    }

    public function test_edits_and_deletes_a_cover_from_the_list(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        $cover = $this->cover(['numero' => 3, 'receitas' => 0, 'comissoes' => 0, 'valor_cotacao' => 0]);

        $page = Livewire::test(ListAccountingCovers::class)
            ->callAction(TestAction::make('edit')->table($cover), ['numero' => 4, 'moeda_id' => 2, 'receitas' => 50, 'comissoes' => 10, 'valor_cotacao' => 5, 'payment_date' => '2026-10-15'])
            ->assertHasNoFormErrors();

        $this->assertEquals([4, 2, 50, 10, 5, '2026-10-15'], [$cover->fresh()->numero, $cover->fresh()->moeda_id, $cover->fresh()->receitas, $cover->fresh()->comissoes, $cover->fresh()->valor_cotacao, $cover->fresh()->payment_date->toDateString()]);

        $page->callAction(TestAction::make('delete')->table($cover));

        $this->assertModelMissing($cover);
        $this->assertSame(0, DB::table('capa_processo')->count());
    }

    public function test_creates_a_cover_from_the_pending_items_of_a_booking(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'Unidade', 'acronym' => 'UN']);
        DB::table('fin_expenses')->insert(['id' => 20, 'name' => 'THC', 'ref_unity' => 1]);
        DB::table('fin_expenses_values')->insert(['id' => 20, 'ref_expense' => 20, 'ref_embarque' => 10]);
        DB::table('fin_charges')->insert(['id' => 1, 'ref_type' => 1, 'ref_booking' => 10, 'created_at' => '2026-10-01', 'fine' => 3]);
        DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_expense' => 20, 'item_value' => 300, 'ref_currency' => 1, 'created_at' => '2026-10-01']);

        Livewire::test(ListAccountingCovers::class)
            ->callAction('newCover', [
                'booking_id' => 10,
                'currency_id' => 2,
                'commission' => 0,
                'rate' => 5,
                'revenues' => [['selected' => true, 'id' => 20, 'ta_payment_id' => null, 'name' => 'THC/UN', 'value' => 280]],
                'fine' => [1],
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Capa 1 criada.');

        $this->assertSame([10, 2], [CapaProcesso::first()->embarque_id, (int) CapaProcesso::first()->moeda_id]);
        $this->assertEquals(280, DB::table('capa_processo_receita_item')->value('valor'));
        $this->assertEquals(3, CapaProcesso::first()->valor_multa);
    }

    public function test_choosing_a_booking_in_the_new_cover_form_loads_its_pending_items(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'Unidade', 'acronym' => 'UN']);
        DB::table('fin_expenses')->insert(['id' => 20, 'name' => 'THC', 'ref_unity' => 1]);
        DB::table('fin_expenses_values')->insert(['id' => 20, 'ref_expense' => 20, 'ref_embarque' => 10]);
        DB::table('fin_charges')->insert(['id' => 1, 'ref_type' => 1, 'ref_booking' => 10, 'created_at' => '2026-10-01']);
        DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_expense' => 20, 'item_value' => 300, 'ref_currency' => 1, 'created_at' => '2026-10-01']);

        Livewire::test(ListAccountingCovers::class)
            ->mountAction('newCover')
            ->fillForm(['booking_id' => 10])
            ->assertSchemaStateSet(['revenues' => [['id' => 20, 'name' => 'THC/UN', 'value' => 300.0, 'selected' => false, 'ta_payment_id' => null]]]);
    }

    public function test_downloads_the_cover_pdf_from_the_list(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'i']);
        $cover = $this->cover(['numero' => 12, 'embarque_id' => 10]);

        Livewire::test(ListAccountingCovers::class)
            ->callAction(TestAction::make('pdf')->table($cover))
            ->assertFileDownloaded('capa-12.pdf');
    }

    /** @param array<string, mixed> $attributes */
    private function cover(array $attributes = []): CapaProcesso
    {
        return CapaProcesso::create($attributes + ['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 1]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
