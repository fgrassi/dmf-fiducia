<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Demurrages\DemurrageResource;
use App\Filament\Resources\Demurrages\Pages\ListDemurrages;
use App\Filament\Resources\Detentions\DetentionResource;
use App\Filament\Resources\Detentions\Pages\ListDetentions;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\DemurrageRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\DetentionRelationManager;
use App\Models\Embarque;
use App\Models\FinDemurrage;
use App\Models\FinDetention;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ContainerTimeResourcesTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-16 10:00');
        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => ''])));
    }

    public function test_demurrage_list_opens_on_the_open_containers_and_the_other_tabs_filter_the_rest(): void
    {
        $booking = $this->booking();
        $open = $this->demurrage($booking, ['container' => 'OPEN1', 'data_retirada' => '2026-11-01']);
        $closed = $this->demurrage($booking, ['container' => 'DONE1', 'data_retirada' => '2026-11-01', 'data_devolucao' => '2026-11-05']);

        $this->get(DemurrageResource::getUrl())->assertOk();

        Livewire::test(ListDemurrages::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed])
            ->set('activeTab', 'fechados')
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$open])
            ->set('activeTab', 'todos')
            ->assertCanSeeTableRecords([$open, $closed]);
    }

    public function test_demurrage_list_shows_the_booking_data_and_the_charged_days(): void
    {
        $carrier = DB::table('armador')->insertGetId(['nome' => 'MAERSK']);
        $client = DB::table('pessoa')->insertGetId(['nome' => 'ACME']);
        $booking = $this->booking(['armador_id' => $carrier, 'cliente_id' => $client, 'booking' => 'IMP77']);
        $row = $this->demurrage($booking, ['data_retirada' => '2026-11-01', 'data_devolucao' => '2026-11-05', 'free_time' => 3]);

        Livewire::test(ListDemurrages::class)
            ->set('activeTab', 'todos')
            ->assertSeeInOrder(['MAERSK', 'ACME', 'IMP77', 'ABCU1'])
            ->assertTableColumnStateSet('overdue_days', 2, $row);
    }

    public function test_demurrage_edit_saves_the_return_and_refuses_a_future_date(): void
    {
        $booking = $this->booking(['chegada_efetiva' => '2026-11-01']);
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => 1]);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => '40HC']);
        $row = $this->demurrage($booking, ['data_retirada' => '2026-11-01']);

        Livewire::test(ListDemurrages::class)
            ->callAction(TestAction::make(EditAction::class)->table($row), ['container' => 'ABCU1', 'ref_tipo_container' => 1, 'data_devolucao' => '2026-11-17'])
            ->assertHasActionErrors(['data_devolucao']);

        Livewire::test(ListDemurrages::class)
            ->callAction(TestAction::make(EditAction::class)->table($row), ['container' => 'ABCU1', 'ref_tipo_container' => 1, 'data_devolucao' => '2026-11-05'])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-11-05', $row->fresh()->data_devolucao->toDateString());
        $this->assertSame(1, DB::table('fin_demurrage')->count());
    }

    public function test_detention_tabs_split_charged_containers_and_bookings_without_free_time(): void
    {
        $withFreeTime = $this->booking(['impor_expor' => 'e', 'free_time_detention' => 2]);
        $withoutFreeTime = $this->booking(['impor_expor' => 'e']);
        $charged = $this->detention($withFreeTime, ['container' => 'LATE1', 'data_retirada' => '2026-11-01', 'data_devolucao' => '2026-11-10']);
        $inTime = $this->detention($withFreeTime, ['container' => 'FINE1', 'data_retirada' => '2026-11-01', 'data_devolucao' => '2026-11-02']);
        $noFreeTime = $this->detention($withoutFreeTime, ['container' => 'NOFT1']);

        $this->get(DetentionResource::getUrl())->assertOk();

        Livewire::test(ListDetentions::class)
            ->set('activeTab', 'detention')
            ->assertCanSeeTableRecords([$charged])
            ->assertCanNotSeeTableRecords([$inTime, $noFreeTime])
            ->set('activeTab', 'sem_free_time')
            ->assertCanSeeTableRecords([$noFreeTime])
            ->assertCanNotSeeTableRecords([$charged, $inTime]);
    }

    public function test_booking_tabs_only_show_for_their_operation(): void
    {
        $import = $this->booking();
        $export = $this->booking(['impor_expor' => 'e']);

        $this->assertTrue(DemurrageRelationManager::canViewForRecord($import, ViewEmbarque::class));
        $this->assertFalse(DemurrageRelationManager::canViewForRecord($export, ViewEmbarque::class));
        $this->assertTrue(DetentionRelationManager::canViewForRecord($export, ViewEmbarque::class));
        $this->assertFalse(DetentionRelationManager::canViewForRecord($import, ViewEmbarque::class));
    }

    public function test_booking_demurrage_tab_adds_edits_and_removes_containers(): void
    {
        $booking = $this->booking(['chegada_efetiva' => '2026-11-01']);
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => 1]);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => '40HC']);

        $component = Livewire::test(DemurrageRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), ['container' => 'ABCU1', 'ref_tipo_container' => 1, 'free_time' => 7, 'data_devolucao' => '2026-11-05'])
            ->assertHasNoActionErrors()
            ->assertSee(['ABCU1', '40HC']);

        $row = $booking->demurrages()->first();
        $this->assertSame(['2026-11-01', 7], [$row->data_retirada->toDateString(), (int) $row->free_time]);

        $component->callAction(TestAction::make(CreateAction::class)->table(), ['container' => 'ABCU1', 'ref_tipo_container' => 1, 'free_time' => 7]);
        $this->assertSame(1, $booking->demurrages()->count(), 'container repetido não entra');

        Livewire::test(DemurrageRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make(DeleteAction::class)->table($row));
        $this->assertSame(0, $booking->demurrages()->count());
    }

    /** @param array<string, mixed> $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'BKG'.random_int(1000, 9999), 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'finalizado' => 'n']));
    }

    /** @param array<string, mixed> $attributes */
    private function demurrage(Embarque $booking, array $attributes = []): FinDemurrage
    {
        return FinDemurrage::find(DB::table('fin_demurrage')->insertGetId($attributes + ['container' => 'ABCU1', 'ref_embarque' => $booking->getKey(), 'ref_tipo_container' => 1, 'created_at' => '2026-11-01', 'created_by' => 7]));
    }

    /** @param array<string, mixed> $attributes */
    private function detention(Embarque $booking, array $attributes = []): FinDetention
    {
        return FinDetention::find(DB::table('fin_detention')->insertGetId($attributes + ['container' => 'ABCU1', 'ref_embarque' => $booking->getKey(), 'created_at' => '2026-11-01', 'created_by' => 7]));
    }
}
