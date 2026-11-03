<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Boxes\BoxResource;
use App\Filament\Resources\Boxes\Pages\ListBoxes;
use App\Filament\Resources\Boxes\Pages\ViewBox;
use App\Filament\Resources\Boxes\RelationManagers\BookingsRelationManager;
use App\Models\ControleCaixa;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BoxResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_financial_credential(): void
    {
        $this->actingAs($this->user('booking'))->get(BoxResource::getUrl())->assertForbidden();
    }

    public function test_lists_open_boxes_first_with_the_number_of_bookings(): void
    {
        $this->actingAs($this->user('financial'));
        $closed = $this->box(['status' => 'f', 'nome_caixa' => 'FECHADA']);
        $open = $this->box(['nome_caixa' => 'ABERTA']);
        $open->bookings()->create(['embarque_id' => 1]);
        $open->bookings()->create(['embarque_id' => 2]);

        Livewire::test(ListBoxes::class)
            ->assertCanSeeTableRecords([$open, $closed], inOrder: true)
            ->assertSee(['Aberta', 'Fechada', 'Importação']);
    }

    public function test_filters_boxes_by_booking(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10']);
        $with = $this->box();
        $without = $this->box();
        $with->bookings()->create(['embarque_id' => 10]);

        Livewire::test(ListBoxes::class)
            ->filterTable('booking', ['booking_id' => 10])
            ->assertCanSeeTableRecords([$with])
            ->assertCanNotSeeTableRecords([$without]);
    }

    public function test_view_page_shows_the_box_and_downloads_its_label(): void
    {
        $this->actingAs($this->user('financial'));
        $box = $this->box(['nome_caixa' => 'IMPO 2026']);

        Livewire::test(ViewBox::class, ['record' => $box->getKey()])
            ->assertSee(['IMPO 2026', 'Aberta', 'Importação'])
            ->callAction('etiqueta')
            ->assertFileDownloaded("caixa-{$box->getKey()}.pdf");
    }

    public function test_view_page_closes_an_open_box_and_hides_the_action_afterwards(): void
    {
        $this->actingAs($this->user('financial'));
        $box = $this->box();

        Livewire::test(ViewBox::class, ['record' => $box->getKey()])
            ->assertActionVisible('darBaixa')
            ->callAction('darBaixa')
            ->assertRedirect(BoxResource::getUrl('view', ['record' => $box]));

        $this->assertSame('f', $box->fresh()->status);

        Livewire::test(ViewBox::class, ['record' => $box->getKey()])->assertActionHidden('darBaixa');
    }

    public function test_includes_edits_and_removes_a_booking_in_the_box(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'house_bl' => 'HBL10']);
        $box = $this->box();
        $manager = fn () => Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $box, 'pageClass' => ViewBox::class]);

        $manager()->callAction(TestAction::make('incluir')->table(), ['booking_id' => 10, 'obs' => 'Original']);
        $item = $box->bookings()->first();

        $this->assertSame('Original', $item->obs);

        $manager()
            ->assertCanSeeTableRecords([$item])
            ->callAction(TestAction::make('edit')->table($item), ['obs' => 'Corrigida']);
        $this->assertSame('Corrigida', $item->fresh()->obs);

        $manager()->callAction(TestAction::make('delete')->table($item));
        $this->assertSame(0, $box->bookings()->count());
    }

    public function test_refuses_a_booking_that_is_already_in_another_box(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10']);
        $this->box()->bookings()->create(['embarque_id' => 10]);
        $box = $this->box();

        Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $box, 'pageClass' => ViewBox::class])
            ->callAction(TestAction::make('incluir')->table(), ['booking_id' => 10])
            ->assertNotified('Embarque já incluído em outra Caixa.');

        $this->assertSame(0, $box->bookings()->count());
    }

    /** @param array<string, mixed> $attributes */
    private function box(array $attributes = []): ControleCaixa
    {
        return ControleCaixa::create($attributes + ['nome_caixa' => 'CX', 'tipo_caixa' => 'i', 'status' => 'a']);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
