<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Bls\BlResource;
use App\Filament\Resources\Bls\Pages\CreateBl;
use App\Filament\Resources\Bls\Pages\EditBl;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\BlsRelationManager;
use App\Models\Bl;
use App\Models\Embarque;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingBlsTabTest extends TestCase
{
    use LegacySchema;

    public function test_creates_edits_and_deletes_a_bl_on_their_own_pages(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();

        $this->create($booking)
            ->fillForm(['bl' => 'HBL-1', 'freight_at' => 'ORIGIN', 'confirm_no_document_shipper' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();
        $bl = DB::table('bl')->first();
        $this->assertSame(['HBL-1', 'ORIGIN'], [$bl->bl, $bl->freight_at]);

        $this->edit($booking, $bl->bl_id)
            ->fillForm(['mbl_number' => 'MBL-9', 'confirm_no_document_shipper' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('MBL-9', $booking->fresh()->master_bl);

        $this->edit($booking, $bl->bl_id)
            ->callAction('delete');
        $this->assertSame(0, DB::table('bl')->count());
        $this->assertSame(0, (int) $booking->fresh()->qtd_bl);
    }

    public function test_the_tab_opens_the_pages_in_another_browser_tab(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();
        $bl = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'HBL-1', 'status' => 'aberto']);

        $this->tab($booking)
            ->assertActionHasUrl(TestAction::make('novo')->table(), BlResource::getUrl('create', ['embarque' => $booking]))
            ->assertActionShouldOpenUrlInNewTab(TestAction::make('novo')->table())
            ->assertActionHasUrl(TestAction::make('editar')->table($bl->getKey()), BlResource::getUrl('edit', ['embarque' => $booking, 'record' => $bl]))
            ->assertActionShouldOpenUrlInNewTab(TestAction::make('editar')->table($bl->getKey()));
    }

    public function test_freight_payable_at_is_required_only_on_exports(): void
    {
        $this->actingAs($this->user());

        $this->create($this->booking())
            ->fillForm(['bl' => 'HBL-1', 'confirm_no_document_shipper' => true])
            ->call('create')
            ->assertHasFormErrors(['freight_at' => 'required']);

        $this->create($this->booking(['impor_expor' => 'i']))
            ->fillForm(['bl' => 'HBL-2', 'confirm_no_document_consignee' => true])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_saving_without_a_valid_cnpj_needs_the_confirmation(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();

        $this->create($booking)
            ->fillForm(['bl' => 'HBL-1', 'freight_at' => 'ORIGIN', 'shipper' => 'ACME LTDA'])
            ->call('create')
            ->assertHasFormErrors(['confirm_no_document_shipper']);

        $this->create($booking)
            ->fillForm(['bl' => 'HBL-1', 'freight_at' => 'ORIGIN', 'shipper' => "ACME LTDA\nCNPJ: 11.222.333/0001-81"])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_rnc_is_required_for_the_mc_logistics_agent(): void
    {
        $this->actingAs($this->user());

        $this->create($this->booking(['pessoa_id_representante' => 43]))
            ->fillForm(['bl' => 'HBL-1', 'freight_at' => 'ORIGIN', 'confirm_no_document_shipper' => true])
            ->call('create')
            ->assertHasFormErrors(['rnc' => 'required']);
    }

    public function test_container_rules_follow_the_legacy_screen(): void
    {
        $this->actingAs($this->user());
        DB::table('tipo_container')->insert([['tipo_container_id' => 1, 'nome' => "40' HC"], ['tipo_container_id' => 8, 'nome' => "40' RF"]]);
        $booking = $this->booking();
        $container = ['tipo_container_id' => 1, 'numero' => 'ABCD1234567', 'tara' => '3.800', 'seals_n1' => 'S1', 'qty_pkgs' => '10', 'gross_weight' => '1000,5', 'net_weight' => '900', 'volume' => '30,5'];
        $add = fn (array $row) => $this->create($booking)->fillForm(['bl' => 'HBL-1'])->set('data.container_entry', $row)->call('addContainer');

        $add([...$container, 'volume' => '1.234,5'])->assertHasErrors(['data.container_entry.volume']);
        $add([...$container, 'gross_weight' => 'abc'])->assertHasErrors(['data.container_entry.gross_weight']);
        $add([...$container, 'net_weight' => '0'])->assertHasErrors(['data.container_entry.net_weight']);
        $add([...$container, 'seals_n1' => ''])->assertHasErrors(['data.container_entry.seals_n1']);
        $add([...$container, 'tipo_container_id' => 8])->assertHasErrors(['data.container_entry.temperatura']);
        $this->create($booking)->set('data.container_entry', $container)->call('addContainer')->assertHasErrors(['data.bl']);

        $add($container)->assertHasNoErrors()->assertSet('data.container_entry', []);
        $add([...$container, 'tipo_container_id' => 8, 'temperatura' => -18])->assertHasNoErrors();
    }

    public function test_containers_are_added_edited_copied_and_removed_from_the_list(): void
    {
        $this->actingAs($this->user());
        $page = $this->create($this->booking())->fillForm(['bl' => 'HBL-1']);
        $row = ['tipo_container_id' => 1, 'numero' => 'ABCD1234567', 'tara' => '3.800', 'seals_n1' => 'S1', 'qty_pkgs' => '10', 'gross_weight' => '1000,5', 'net_weight' => '900', 'volume' => '30,25'];

        $page->set('data.container_entry', $row)->call('addContainer');
        $key = array_key_first($page->get('data.containers'));

        $page->call('copyContainer', $key)
            ->assertSet('data.container_entry', ['tipo_container_id' => 1, 'qty_pkgs' => '10', 'gross_weight' => '1000,5', 'net_weight' => '900', 'volume' => '30,25'])
            ->assertSet('data.container_editing', null)
            ->call('cancelContainerEdit')
            ->assertSet('data.container_entry', []);

        $page->call('editContainer', $key)
            ->assertSet('data.container_editing', $key)
            ->set('data.container_entry.numero', 'ZZZZ7654321')
            ->call('addContainer')
            ->assertSet('data.container_editing', null);
        $this->assertSame(['ZZZZ7654321'], collect($page->get('data.containers'))->pluck('numero')->all());

        $page->call('removeContainer', $key)->assertSet('data.containers', [])->assertSet('data.gross_weight', '0,000');
    }

    public function test_the_edit_page_lists_the_saved_containers_and_saves_changes_to_them(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();
        $bl = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'HBL-1', 'status' => 'aberto', 'freight_at' => 'ORIGIN', 'shipper' => "ACME\nCNPJ: 11.222.333/0001-81"]);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => "40' HC"]);
        DB::table('container')->insert(['bl_id' => $bl->getKey(), 'tipo_container_id' => 1, 'numero' => 'ABCD1234567', 'tara' => '3', 'seals_n1' => 'S1', 'seals_n2' => 'S2', 'qty_pkgs' => '1', 'gross_weight' => '10', 'net_weight' => '9', 'volume' => '1']);
        $id = DB::table('container')->value('container_id');

        $page = $this->edit($booking, $bl->getKey())
            ->assertSee("40' HC")
            ->assertSee('ABCD1234567')
            ->assertSee('S1 | S2');

        $key = array_key_first($page->get('data.containers'));
        $page->call('editContainer', $key)
            ->set('data.container_entry.tara', '4')
            ->call('addContainer')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([[$id, '4']], DB::table('container')->get(['container_id', 'tara'])->map(fn ($c) => [(int) $c->container_id, $c->tara])->all());
    }

    public function test_keep_values_leaves_type_quantity_weights_and_volume_in_the_entry_row(): void
    {
        $this->actingAs($this->user());
        $row = ['tipo_container_id' => 1, 'numero' => 'ABCD1234567', 'tara' => '3.800', 'seals_n1' => 'S1', 'qty_pkgs' => '10', 'gross_weight' => '1000,5', 'net_weight' => '900', 'volume' => '30,25'];

        $this->create($this->booking())
            ->fillForm(['bl' => 'HBL-1'])
            ->set('data.container_keep', true)
            ->set('data.container_entry', $row)
            ->call('addContainer')
            ->assertSet('data.container_entry', ['tipo_container_id' => 1, 'qty_pkgs' => '10', 'gross_weight' => '1000,5', 'net_weight' => '900', 'volume' => '30,25']);
    }

    public function test_the_bl_weights_and_volume_follow_the_sum_of_the_containers_and_save_with_the_bl(): void
    {
        $this->actingAs($this->user());
        $row = ['tipo_container_id' => 1, 'tara' => '3', 'seals_n1' => 'S1', 'qty_pkgs' => '1'];
        $page = $this->create($this->booking())->fillForm(['bl' => 'HBL-1', 'freight_at' => 'ORIGIN', 'confirm_no_document_shipper' => true]);

        $page->set('data.container_entry', [...$row, 'numero' => 'A', 'gross_weight' => '1000,5', 'net_weight' => '900', 'volume' => '30,25'])->call('addContainer')
            ->set('data.container_entry', [...$row, 'numero' => 'B', 'gross_weight' => '2000', 'net_weight' => '1,5', 'volume' => '10'])->call('addContainer')
            ->assertSet('data.gross_weight', '3.000,500')
            ->assertSet('data.net_weight', '901,500')
            ->assertSet('data.volume', '40,250')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['A', 'B'], DB::table('container')->orderBy('container_id')->pluck('numero')->all());
        $this->assertSame('3.000,500', DB::table('bl')->value('gross_weight'));
    }

    public function test_the_saved_party_picker_only_brings_texts_of_the_bookings_own_party(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking(['impor_expor' => 'i', 'pessoa_id_cliente' => 5]);
        $own = DB::table('cmn_dados_bl')->insertGetId(['ref_pessoa' => 5, 'dados' => 'MY SHIPPER']);
        $other = DB::table('cmn_dados_bl')->insertGetId(['ref_pessoa' => 99, 'dados' => 'SOMEONE ELSE']);

        $this->create($booking)
            ->set('data.saved_shipper', $other)
            ->assertSet('data.shipper', null)
            ->set('data.saved_shipper', $own)
            ->assertSet('data.shipper', 'MY SHIPPER');
    }

    public function test_save_and_finalize_approves_the_bl_and_stores_its_pdf(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($this->user());

        $this->create($this->booking())
            ->fillForm(['bl' => 'HBL-1', 'freight_at' => 'ORIGIN', 'confirm_no_document_shipper' => true])
            ->call('createAndFinalize')
            ->assertHasNoFormErrors();

        $this->assertSame('aprovado', DB::table('bl')->value('status'));
        $this->assertSame(1, DB::table('emb_embarque_files')->count());
    }

    public function test_only_an_open_bl_offers_save_and_finalize(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();
        $open = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'OPEN', 'status' => Bl::OPEN]);
        $approved = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'DONE', 'status' => Bl::APPROVED]);

        $offers = fn (Bl $bl): bool => collect((fn () => $this->getFormActions())->call($this->edit($booking, $bl->getKey())->instance()))
            ->contains(fn ($action): bool => $action->getName() === 'salvarEFinalizar' && $action->isVisible());

        $this->assertTrue($offers($open));
        $this->assertFalse($offers($approved));
    }

    public function test_prints_the_original_from_the_page_and_counts_it(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();
        $bl = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'HBL-1', 'status' => 'aberto']);

        $this->edit($booking, $bl->getKey())
            ->callAction('imprimirOriginal', ['marks' => true])
            ->assertFileDownloaded();

        $this->assertSame(1, (int) $bl->fresh()->cntOriginal);
    }

    public function test_imports_the_selected_online_drafts_from_the_tab(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();
        $draft = DB::table('bl_online')->insertGetId(['embarque_id' => $booking->getKey(), 'draft_id' => 1, 'shipper' => 'ACME', 'consignee_tax_id' => '', 'notify_tax_id' => '', 'shipper_tax_id' => '']);

        $this->tab($booking)
            ->callAction(TestAction::make('draftOnline')->table(), ['ids' => [$draft]])
            ->assertHasNoActionErrors();

        $this->assertSame(['ACME', 'f'], [DB::table('bl')->value('shipper'), DB::table('bl')->value('status_bl_online')]);
    }

    public function test_damages_are_saved_and_the_protest_letter_downloads_on_demand(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();
        $bl = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'HBL-1']);
        $container = DB::table('container')->insertGetId(['bl_id' => $bl->getKey(), 'numero' => 'ABCD1234567']);

        $tab = $this->tab($booking)
            ->callAction(TestAction::make('avarias')->table(), ['damages' => [$container => 'amassado']])
            ->assertHasNoActionErrors();
        $this->assertSame('amassado', DB::table('container')->value('observacao_avaria'));

        $tab->callAction(TestAction::make('avarias')->table(), ['damages' => [$container => 'amassado']], ['pdf' => true])
            ->assertFileDownloaded();
    }

    public function test_the_online_bl_switch_only_shows_for_online_bls_and_the_booking_import_only_for_cancelled_bookings(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();
        $online = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'ON', 'status_bl_online' => 'f']);
        $manual = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'MAN']);

        $this->tab($booking)
            ->assertActionVisible(TestAction::make('alternarBlOnline')->table($online->getKey()))
            ->assertActionHidden(TestAction::make('alternarBlOnline')->table($manual->getKey()))
            ->assertActionHidden(TestAction::make('importarDadosReserva')->table($online->getKey()))
            ->callAction(TestAction::make('alternarBlOnline')->table($online->getKey()));
        $this->assertSame('a', $online->fresh()->status_bl_online);

        $cancelled = $this->booking(['finalizado' => 'c', 'booking' => 'CANCELLED-1']);
        $bl = Bl::create(['embarque_id' => $cancelled->getKey(), 'bl' => 'X']);

        $this->tab($cancelled)
            ->assertActionVisible(TestAction::make('importarDadosReserva')->table($bl->getKey()))
            ->callAction(TestAction::make('importarDadosReserva')->table($bl->getKey()));
        $this->assertSame('CANCELLED-1', $bl->fresh()->bl);
    }

    public function test_the_edit_page_imports_a_draft_spreadsheet(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking();
        $bl = Bl::create(['embarque_id' => $booking->getKey(), 'bl' => 'HBL-1', 'status' => 'aberto']);
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue([1, 3], 'IMPORTED SHIPPER');
        $book->createSheet();
        $path = tempnam(sys_get_temp_dir(), 'draft').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->edit($booking, $bl->getKey())
            ->callAction('importarDraft', ['arquivo' => UploadedFile::fake()->createWithContent('draft.xlsx', file_get_contents($path))])
            ->assertHasNoActionErrors()
            ->assertSet('data.shipper', 'IMPORTED SHIPPER');

        $this->assertSame('IMPORTED SHIPPER', $bl->fresh()->shipper);
    }

    public function test_the_tab_is_hidden_on_suspended_bookings(): void
    {
        $this->assertTrue(BlsRelationManager::canViewForRecord($this->booking(), ViewEmbarque::class));
        $this->assertFalse(BlsRelationManager::canViewForRecord($this->booking(['finalizado' => 'p']), ViewEmbarque::class));
    }

    private function create(Embarque $booking): mixed
    {
        return Livewire::test(CreateBl::class, ['parentRecord' => $booking]);
    }

    private function edit(Embarque $booking, int $id): mixed
    {
        return Livewire::test(EditBl::class, ['parentRecord' => $booking, 'record' => $id]);
    }

    private function tab(Embarque $booking): mixed
    {
        return Livewire::test(BlsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class]);
    }

    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'EXP'.random_int(1000, 9999), 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'pessoa_id_representante' => 55]));
    }

    private function user(): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.random_int(1, 99999), 'senha' => 'x', 'status' => 1, 'credentials' => 'booking']));
    }
}
