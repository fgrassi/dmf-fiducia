<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\CreditNotesRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\DebitNotesRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\ExpensesRelationManager;
use App\Models\Embarque;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingNotesTabTest extends TestCase
{
    use LegacySchema;

    public function test_creates_a_note_adds_an_expense_and_removes_it_with_a_justification(): void
    {
        $this->actingAs($this->user('booking,financial'));
        $booking = $this->booking();
        DB::table('fin_expenses')->insert(['id' => 10, 'name' => 'Frete']);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $booking->getKey(), 'ref_expense' => 10, 'buying_rate' => 100, 'selling_rate' => 160]);

        $tab = Livewire::test(DebitNotesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('nova')->table())
            ->assertHasNoActionErrors();
        $note = DB::table('fin_debit_notes')->first();

        $tab->assertCanSeeTableRecords([$note->id])
            ->callAction(TestAction::make('adicionarDespesa')->table($note->id), ['expense' => $expense])
            ->assertHasNoActionErrors();
        $this->assertEquals(60.0, DB::table('fin_debit_notes')->find($note->id)->gross_profit);

        $tab->callAction(TestAction::make('remover')->table($note->id), ['justification' => 'engano'])
            ->assertHasNoActionErrors();
        $this->assertSame(0, DB::table('fin_debit_notes')->count());
    }

    public function test_credit_tab_marks_the_booking_as_needing_no_note(): void
    {
        $this->actingAs($this->user('booking'));
        $booking = $this->booking();

        Livewire::test(CreditNotesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('semNota')->table(), ['justification' => 'sem receita'])
            ->assertHasNoActionErrors();

        $this->assertNotNull($booking->fresh()->controle_credit);
    }

    public function test_generates_a_note_from_the_selected_expenses_only_on_exports(): void
    {
        $this->actingAs($this->user('booking'));
        $export = $this->booking();
        $import = $this->booking(['impor_expor' => 'i']);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $export->getKey(), 'ref_expense' => 10, 'buying_rate' => 1, 'selling_rate' => 3]);

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $export, 'pageClass' => ViewEmbarque::class])
            ->selectTableRecords([$expense])
            ->callAction(TestAction::make('gerarNotaCredito')->table()->bulk())
            ->assertNotified('CN 0001/'.now()->format('Y').' gerada.');
        $this->assertSame(1, DB::table('fin_credit_note_items')->count());

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $import, 'pageClass' => ViewEmbarque::class])
            ->assertActionHidden(TestAction::make('gerarNotaDebito')->table()->bulk());
    }

    public function test_a_cancelled_booking_hides_the_tabs_from_users_without_director_or_financial(): void
    {
        $cancelled = $this->booking(['finalizado' => 'c']);

        $this->actingAs($this->user('booking'));
        $this->assertFalse(DebitNotesRelationManager::canViewForRecord($cancelled, ViewEmbarque::class));

        $this->actingAs($this->user('booking,financial'));
        $this->assertTrue(DebitNotesRelationManager::canViewForRecord($cancelled, ViewEmbarque::class));
        $this->assertTrue(CreditNotesRelationManager::canViewForRecord($this->booking(), ViewEmbarque::class));
    }

    public function test_generates_the_pdf_converts_to_euro_and_sends_the_note_from_the_tab(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($this->user('booking,financial'));
        DB::table('cmn_currencies')->insert([['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$'], ['id' => 3, 'name' => 'Euro', 'symbol' => '€']]);
        $booking = $this->booking(['booking' => 'EXP77']);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $booking->getKey(), 'ref_expense' => 10, 'buying_rate' => 100, 'selling_rate' => 160]);
        $tab = Livewire::test(CreditNotesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('nova')->table());
        $note = DB::table('fin_credit_notes')->first();
        DB::table('fin_credit_note_items')->insert(['ref_credit_note' => $note->id, 'ref_expense' => $expense]);

        $tab->callAction(TestAction::make('enviar')->table($note->id))
            ->assertNotified('Gere o PDF da nota antes de enviar.');

        $tab->callAction(TestAction::make('pdf')->table($note->id))
            ->assertFileDownloaded();
        $this->assertNotNull(DB::table('fin_credit_notes')->find($note->id)->ref_file);

        $tab->callAction(TestAction::make('converterEuro')->table($note->id), ['euro_rate' => 6.2, 'factor' => 0.9])
            ->assertHasNoActionErrors()
            ->assertActionHidden(TestAction::make('converterEuro')->table($note->id));
        $this->assertSame(3, (int) DB::table('fin_credit_notes')->find($note->id)->moeda_id);

        $tab->callAction(TestAction::make('enviar')->table($note->id))
            ->assertNotified('Nota enviada.');
        $this->assertSame(1, (int) DB::table('fin_credit_notes')->find($note->id)->status);
    }

    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'EXP'.random_int(1000, 9999), 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'pessoa_id_representante' => 55]));
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.random_int(1, 99999), 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
