<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Couriers\CourierResource;
use App\Filament\Resources\Couriers\Pages\ListCouriers;
use App\Filament\Resources\Couriers\Pages\ViewCourier;
use App\Filament\Resources\Couriers\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Couriers\RelationManagers\NamesRelationManager;
use App\Models\ControleCourier;
use App\Models\CtrCourierEmbarque;
use App\Models\CtrCourierSemEmbarque;
use App\Models\FinDespesasVariadas;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CourierResourceTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tipo_courier')->insert([['nome' => 'DHL'], ['nome' => 'FEDEX']]);
    }

    public function test_forbids_user_without_auxiliares_credential(): void
    {
        $this->actingAs($this->user('financial'))->get(CourierResource::getUrl())->assertForbidden();
        $this->actingAs($this->user('auxiliares'))->get(CourierResource::getUrl())->assertOk();
    }

    public function test_lists_by_receipt_date_newest_first_with_account_and_link_status(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $old = $this->courier(['numero' => 'ANTIGO', 'data_recebimento' => '2026-01-10']);
        $new = $this->courier(['numero' => 'NOVO', 'data_recebimento' => '2026-10-06', 'status_vinculo_nota' => 's', 'valor' => 1234.5]);

        Livewire::test(ListCouriers::class)
            ->assertCanSeeTableRecords([$new, $old], inOrder: true)
            ->assertSee(['SIM', 'NÃO']);
    }

    public function test_filters_by_number_note_date_type_and_account_without_running_text_as_sql(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $hit = $this->courier(['numero' => 'AWB-77', 'nota' => 'N-900', 'data_recebimento' => '2026-10-06', 'tipo_courier_id' => 2, 'conta_utilizada' => 'a']);
        $miss = $this->courier(['numero' => 'OUTRO', 'nota' => 'X', 'data_recebimento' => '2026-10-07']);

        Livewire::test(ListCouriers::class)
            ->filterTable('busca', ['numero' => 'AWB'])
            ->assertCanSeeTableRecords([$hit])->assertCanNotSeeTableRecords([$miss])
            ->filterTable('busca', ['nota' => '900'])
            ->assertCanSeeTableRecords([$hit])->assertCanNotSeeTableRecords([$miss])
            ->filterTable('busca', ['data_recebimento' => '2026-10-06'])
            ->assertCanSeeTableRecords([$hit])->assertCanNotSeeTableRecords([$miss])
            ->filterTable('busca', ['numero' => "' or 1=1 --"])
            ->assertCanNotSeeTableRecords([$hit, $miss])
            ->removeTableFilter('busca')
            ->filterTable('tipo_courier_id', 2)
            ->assertCanSeeTableRecords([$hit])->assertCanNotSeeTableRecords([$miss])
            ->removeTableFilter('tipo_courier_id')
            ->filterTable('conta_utilizada', 'a')
            ->assertCanSeeTableRecords([$hit])->assertCanNotSeeTableRecords([$miss]);
    }

    public function test_filters_by_booking_once_per_courier_even_with_several_bookings(): void
    {
        $this->actingAs($this->user('auxiliares'));
        DB::table('embarque')->insert([['embarque_id' => 10, 'booking' => 'BKG10'], ['embarque_id' => 11, 'booking' => 'BKG11']]);
        $with = $this->courier();
        $without = $this->courier();
        DB::table('ctr_courier_embarque')->insert([['controle_courier_id' => $with->getKey(), 'embarque_id' => 10], ['controle_courier_id' => $with->getKey(), 'embarque_id' => 11]]);

        Livewire::test(ListCouriers::class)
            ->filterTable('busca', ['booking' => 'BKG1'])
            ->assertCanSeeTableRecords([$with])
            ->assertCanNotSeeTableRecords([$without])
            ->assertCountTableRecords(1);
    }

    public function test_edits_the_columns_in_the_row_and_validates_them(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier(['valor' => 10]);
        $id = (string) $courier->getKey();

        Livewire::test(ListCouriers::class)
            ->call('updateTableColumnState', 'numero', $id, 'AWB-2')
            ->call('updateTableColumnState', 'data_recebimento', $id, '2026-09-01')
            ->call('updateTableColumnState', 'tipo_courier_id', $id, '2')
            ->call('updateTableColumnState', 'conta_utilizada', $id, 'a')
            ->call('updateTableColumnState', 'valor', $id, '1234.56')
            ->call('updateTableColumnState', 'numero', $id, '');

        $this->assertEquals(['AWB-2', '2026-09-01', 2, 'a', 1234.56], array_values($courier->fresh()->only(['numero', 'data_recebimento', 'tipo_courier_id', 'conta_utilizada', 'valor'])));
    }

    public function test_a_courier_with_linked_note_is_locked_in_the_row_and_has_no_note_or_delete_action(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier(['status_vinculo_nota' => 's', 'valor' => 10, 'nota' => 'N-1']);

        Livewire::test(ListCouriers::class)
            ->call('updateTableColumnState', 'valor', (string) $courier->getKey(), '99')
            ->call('updateTableColumnState', 'numero', (string) $courier->getKey(), 'TROCADO')
            ->assertTableActionHidden('nota', $courier)
            ->assertTableActionHidden('excluir', $courier);

        $this->assertSame([10.0, 'N1'], [$courier->fresh()->valor, $courier->fresh()->numero]);
    }

    public function test_sets_the_note_in_the_modal_and_requests_a_number_from_the_sequence(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier();

        Livewire::test(ListCouriers::class)
            ->callAction(TestAction::make('nota')->table($courier), ['nota' => 'N-55']);

        $this->assertSame('N-55', $courier->fresh()->nota);

        Livewire::test(ListCouriers::class)
            ->mountAction(TestAction::make('nota')->table($courier))
            ->callAction(TestAction::make('solicitarNumero')->schemaComponent('nota', schema: 'mountedActionSchema0'))
            ->assertSchemaStateSet(['nota' => '1']);
    }

    public function test_includes_a_courier_and_goes_to_its_page(): void
    {
        $this->actingAs($this->user('auxiliares'));

        $page = Livewire::test(ListCouriers::class)
            ->callAction('incluir', ['numero' => 'AWB-9', 'data_recebimento' => '2026-10-06', 'tipo_courier_id' => 1, 'conta_utilizada' => 'd']);

        $courier = ControleCourier::firstWhere('numero', 'AWB-9');
        $page->assertRedirect(CourierResource::getUrl('view', ['record' => $courier]));
        $this->assertNull($courier->nota);
    }

    public function test_requires_number_type_and_date_to_include(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ListCouriers::class)
            ->callAction('incluir', ['numero' => '', 'data_recebimento' => null, 'tipo_courier_id' => null, 'conta_utilizada' => 'd'])
            ->assertHasActionErrors(['numero' => 'required', 'data_recebimento' => 'required', 'tipo_courier_id' => 'required']);

        $this->assertSame(0, ControleCourier::count());
    }

    public function test_removes_an_unlinked_courier_from_the_list(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier();

        Livewire::test(ListCouriers::class)->callAction(TestAction::make('excluir')->table($courier));

        $this->assertSame(0, ControleCourier::count());
    }

    public function test_view_page_shows_the_courier_and_the_file_link(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier(['numero' => 'AWB-5', 'tipo_courier_id' => 1, 'arquivo' => '0610261015_awb.pdf']);

        Livewire::test(ViewCourier::class, ['record' => $courier->getKey()])
            ->assertSee(['AWB-5', 'DHL', 'DMF', '06/10/2026', '0610261015_awb.pdf', 'N/D']);
    }

    public function test_links_and_removes_bookings_on_the_courier_page(): void
    {
        $this->actingAs($this->user('auxiliares'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'house_bl' => 'HBL10']);
        $courier = $this->courier();
        $manager = fn () => Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $courier, 'pageClass' => ViewCourier::class]);

        $manager()->callAction(TestAction::make('incluir')->table(), ['booking_id' => 10, 'observacao' => 'originais']);
        $link = CtrCourierEmbarque::firstWhere('controle_courier_id', $courier->getKey());

        $manager()->assertSee(['BKG10', 'HBL10', 'originais'])
            ->callAction(TestAction::make('remover')->table($link));

        $this->assertSame(0, $courier->bookings()->count());
    }

    public function test_links_and_removes_names_and_keeps_removal_open_after_the_note_is_linked(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier();
        $manager = fn () => Livewire::test(NamesRelationManager::class, ['ownerRecord' => $courier->fresh(), 'pageClass' => ViewCourier::class]);

        $manager()->callAction(TestAction::make('incluir')->table(), ['nome' => 'Fulano']);
        $name = CtrCourierSemEmbarque::firstWhere('controle_courier_id', $courier->getKey());
        $courier->update(['status_vinculo_nota' => 's']);

        $manager()->assertActionHidden(TestAction::make('incluir')->table())
            ->callAction(TestAction::make('delete')->table($name));

        $this->assertSame(0, $courier->names()->count());
    }

    public function test_locked_courier_hides_the_booking_form_and_removal(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier(['status_vinculo_nota' => 's']);
        $link = $courier->bookings()->create(['embarque_id' => 10]);

        Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $courier, 'pageClass' => ViewCourier::class])
            ->assertActionHidden(TestAction::make('incluir')->table())
            ->assertActionHidden(TestAction::make('remover')->table($link));
    }

    public function test_agent_expense_action_shows_only_with_a_booking_and_locks_the_courier_from_the_page(): void
    {
        $this->actingAs($this->user('auxiliares'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'house_bl' => 'HBL10', 'pessoa_id_representante' => 5]);
        $courier = $this->courier(['nota' => 'N-5', 'valor' => 80]);

        Livewire::test(ViewCourier::class, ['record' => $courier->getKey()])->assertActionHidden('despesaAgente');

        $courier->bookings()->create(['embarque_id' => 10]);

        Livewire::test(ViewCourier::class, ['record' => $courier->getKey()])
            ->assertActionVisible('despesaAgente')
            ->callAction('despesaAgente')
            ->assertRedirect(CourierResource::getUrl('view', ['record' => $courier]));

        $this->assertSame(['N-5', 40.0, 's'], [FinDespesasVariadas::first()->invoice, FinDespesasVariadas::first()->valor, $courier->fresh()->status_vinculo_nota]);

        Livewire::test(ListCouriers::class)->assertTableActionHidden('despesaAgente', $courier);
    }

    public function test_agent_expense_action_warns_instead_of_creating_when_the_courier_has_no_note(): void
    {
        $this->actingAs($this->user('auxiliares'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'pessoa_id_representante' => 5]);
        $courier = $this->courier(['valor' => 80]);
        $courier->bookings()->create(['embarque_id' => 10]);

        Livewire::test(ListCouriers::class)
            ->callAction(TestAction::make('despesaAgente')->table($courier))
            ->assertNotified('Informe a nota do courier antes de lançar a despesa.');

        $this->assertSame([0, null], [FinDespesasVariadas::count(), $courier->fresh()->status_vinculo_nota]);
    }

    public function test_pdf_asks_to_enable_the_agent_expense_then_creates_it_and_downloads(): void
    {
        $this->actingAs($this->user('auxiliares'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'pessoa_id_representante' => 5]);
        $courier = $this->courier(['nota' => 'N-5', 'valor' => 80]);
        $courier->bookings()->create(['embarque_id' => 10]);

        Livewire::test(ListCouriers::class)
            ->mountAction(TestAction::make('pdf')->table($courier))
            ->assertActionMounted(TestAction::make('pdf')->table($courier))
            ->callMountedAction()
            ->assertFileDownloaded("courier-{$courier->getKey()}.pdf");

        $this->assertSame([1, 's'], [FinDespesasVariadas::count(), $courier->fresh()->status_vinculo_nota]);
    }

    public function test_pdf_of_a_linked_courier_or_one_without_booking_just_downloads(): void
    {
        $this->actingAs($this->user('auxiliares'));
        $linked = $this->courier(['status_vinculo_nota' => 's']);
        $linked->bookings()->create(['embarque_id' => 10]);
        $plain = $this->courier();

        foreach ([$linked, $plain] as $courier) {
            Livewire::test(ListCouriers::class)
                ->callAction(TestAction::make('pdf')->table($courier))
                ->assertFileDownloaded("courier-{$courier->getKey()}.pdf");
        }

        $this->assertSame(0, FinDespesasVariadas::count());
    }

    public function test_pdf_still_downloads_when_the_expense_is_refused_and_warns_why(): void
    {
        $this->actingAs($this->user('auxiliares'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'pessoa_id_representante' => 5]);
        $courier = $this->courier(['valor' => 80]);
        $courier->bookings()->create(['embarque_id' => 10]);

        Livewire::test(ListCouriers::class)
            ->callAction(TestAction::make('pdf')->table($courier))
            ->assertNotified('Informe a nota do courier antes de lançar a despesa.')
            ->assertFileDownloaded("courier-{$courier->getKey()}.pdf");

        $this->assertSame(0, FinDespesasVariadas::count());
    }

    public function test_uploads_the_courier_file_from_the_page_and_links_it_on_the_page(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier();

        Livewire::test(ViewCourier::class, ['record' => $courier->getKey()])
            ->callAction('enviarArquivo', ['file' => UploadedFile::fake()->createWithContent('awb.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")])
            ->assertRedirect(CourierResource::getUrl('view', ['record' => $courier]));

        $name = $courier->fresh()->arquivo;
        $this->assertStringEndsWith('_awb.pdf', $name);
        Storage::disk('tracking_uploads')->assertExists("courrier/{$name}");

        Livewire::test(ViewCourier::class, ['record' => $courier->getKey()])->assertSee($name);
    }

    public function test_refuses_an_upload_that_is_not_pdf_or_image(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($this->user('auxiliares'));
        $courier = $this->courier();

        Livewire::test(ViewCourier::class, ['record' => $courier->getKey()])
            ->callAction('enviarArquivo', ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')])
            ->assertHasActionErrors(['file']);

        $this->assertNull($courier->fresh()->arquivo);
    }

    private function courier(array $attributes = []): ControleCourier
    {
        return ControleCourier::create($attributes + ['numero' => 'N1', 'data_recebimento' => '2026-10-06', 'tipo_courier_id' => 1, 'conta_utilizada' => 'd']);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
