<?php

namespace Tests\Feature\Actions\Booking\Notes;

use App\Actions\Booking\Notes\ChangeNoteItems;
use App\Actions\Booking\Notes\CreateNote;
use App\Actions\Booking\Notes\RemoveNote;
use App\Actions\Booking\Notes\ToggleNoteNeed;
use App\Actions\Booking\Notes\UpdateNote;
use App\Enums\NoteType;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingNotesTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-05 10:00');
        DB::table('fin_currencies_rates_values')->insert([['rate' => 5.10, 'ref_currency_rate' => 1, 'created_by' => 1, 'rate_date' => '2026-11-03'], ['rate' => 5.50, 'ref_currency_rate' => 1, 'created_by' => 1, 'rate_date' => '2026-11-04']]);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
    }

    public function test_creates_a_debit_note_from_expenses_splitting_the_profit_between_dmf_and_agent(): void
    {
        $booking = $this->booking(['tipo_embarque' => 'm']);
        [$freight, $thc] = [$this->expense($booking, 10, 100, 160), $this->expense($booking, 11, 50, 70)];

        $id = app(CreateNote::class)($booking, NoteType::Debit, [$freight, $thc], 7);

        $note = DB::table('fin_debit_notes')->find($id);
        $this->assertSame('DN 0001/2026', $note->name);
        $this->assertSame('2026-11-05', $note->created_at);
        $this->assertSame('2026-12-05', $note->data_vencimento);
        $this->assertEquals(5.50, $note->dolar_rate);
        $this->assertSame(55, (int) $note->ref_agente);
        $this->assertEquals([80.0, 40.0, 40.0], [$note->gross_profit, $note->due_to_dmf, $note->due_to_dmf_agent]);
        $this->assertSame(2, DB::table('fin_debit_note_items')->where('ref_debit_note', $id)->count());
        $this->assertSame(['Cadastro: Debit Note', 'i', 7], $this->lastTracking());
    }

    public function test_debit_due_date_is_seven_days_outside_maritime(): void
    {
        $id = app(CreateNote::class)($this->booking(['tipo_embarque' => 'a']), NoteType::Debit, [], 7);

        $this->assertSame('2026-11-12', DB::table('fin_debit_notes')->find($id)->data_vencimento);
    }

    public function test_the_commission_purchase_is_taken_out_of_the_debit_profit_and_handling_leaves_it_all_with_dmf(): void
    {
        $booking = $this->booking();
        $this->expense($booking, 239, 30, 0);
        $freight = $this->expense($booking, 10, 100, 200);
        $handling = $this->expense($booking, 1612, 0, 40);

        $withoutHandling = app(CreateNote::class)($booking, NoteType::Debit, [$freight], 7);
        $withHandling = app(CreateNote::class)($booking, NoteType::Debit, [$freight, $handling], 7);

        $this->assertEquals([100.0, 35.0, 35.0], $this->amounts('fin_debit_notes', $withoutHandling));
        $this->assertEquals([140.0, 110.0, 0.0], $this->amounts('fin_debit_notes', $withHandling));
    }

    public function test_exw_expenses_stay_out_of_the_debit_profit_and_go_to_the_observations(): void
    {
        $booking = $this->booking();
        DB::table('fin_expenses_unities')->insert(['id' => 3, 'name' => 'Processo', 'acronym' => 'PR']);
        DB::table('fin_expenses')->insert(['id' => 20, 'name' => 'Inland', 'ref_unity' => 3]);
        $freight = $this->expense($booking, 10, 100, 160);
        $exw = $this->expense($booking, 20, 0, 90, ['flag_exw' => 1, 'ref_currency_sr' => 2]);

        $id = app(CreateNote::class)($booking, NoteType::Debit, [$freight, $exw], 7);

        $note = DB::table('fin_debit_notes')->find($id);
        $this->assertEquals([60.0, 90.0], [$note->gross_profit, $note->exw_expenses]);
        $this->assertSame("EXW EXPENSES:\n\rInland/PR: US$ 90,00 x 1,00 PR = US$ 90,00", $note->obs);
    }

    public function test_an_exw_expense_without_selling_rate_counts_as_zero_instead_of_breaking(): void
    {
        $booking = $this->booking();
        DB::table('fin_expenses_unities')->insert(['id' => 3, 'name' => 'Processo', 'acronym' => 'PR']);
        DB::table('fin_expenses')->insert(['id' => 20, 'name' => 'Inland', 'ref_unity' => 3]);
        $exw = $this->expense($booking, 20, 0, 0, ['flag_exw' => 1, 'selling_rate' => null, 'ref_currency_sr' => 2]);

        $id = app(CreateNote::class)($booking, NoteType::Debit, [$exw], 7);

        $note = DB::table('fin_debit_notes')->find($id);
        $this->assertEquals(0.0, $note->exw_expenses);
        $this->assertSame("EXW EXPENSES:\n\rInland/PR: US$ 0,00 x 1,00 PR = US$ 0,00", $note->obs);
    }

    public function test_the_factor_is_the_bl_count_or_the_container_quantity(): void
    {
        $booking = $this->booking(['qtd_bl' => 2]);
        DB::table('fin_expenses_unities')->insert([['id' => 1, 'name' => 'BL', 'acronym' => 'BL'], ['id' => 2, 'name' => 'Container', 'acronym' => 'CNT']]);
        DB::table('fin_expenses')->insert([['id' => 30, 'name' => 'Doc', 'ref_unity' => 1], ['id' => 31, 'name' => 'THC', 'ref_unity' => 2]]);
        DB::table('mercadoria')->insert([['embarque_id' => $booking->getKey(), 'quantidade' => '2', 'tipo_container_id' => 5], ['embarque_id' => $booking->getKey(), 'quantidade' => '3', 'tipo_container_id' => 6]]);
        $perBl = $this->expense($booking, 30, 10, 20);
        $perContainer = $this->expense($booking, 31, 10, 20, ['ref_equipment' => 6]);
        $allContainers = $this->expense($booking, 31, 10, 20);

        $id = app(CreateNote::class)($booking, NoteType::Credit, [$perBl, $perContainer, $allContainers], 7);

        // (20-10)×2 + (20-10)×3 + (20-10)×5
        $this->assertEquals(100.0, DB::table('fin_credit_notes')->find($id)->gross_profit);
    }

    public function test_adding_and_removing_items_recalculates_and_rejects_foreign_or_repeated_expenses(): void
    {
        $booking = $this->booking();
        $first = $this->expense($booking, 10, 100, 160);
        $second = $this->expense($booking, 11, 50, 70);
        $foreign = $this->expense($this->booking(), 12, 1, 2);
        $id = app(CreateNote::class)($booking, NoteType::Credit, [$first], 7);

        app(ChangeNoteItems::class)->add(NoteType::Credit, $id, $second);
        $this->assertEquals([80.0, 40.0, 40.0], $this->amounts('fin_credit_notes', $id));

        app(ChangeNoteItems::class)->remove(NoteType::Credit, $id, $first);
        $this->assertEquals([20.0, 10.0, 10.0], $this->amounts('fin_credit_notes', $id));

        foreach ([$foreign => 'A despesa não pertence a esta reserva.', $second => 'Esta despesa já está na nota.'] as $expense => $message) {
            try {
                app(ChangeNoteItems::class)->add(NoteType::Credit, $id, $expense);
                $this->fail('A despesa deveria ter sido recusada.');
            } catch (DomainException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_adding_and_removing_items_keep_the_typed_exw_expenses_and_observations(): void
    {
        $booking = $this->booking();
        $first = $this->expense($booking, 10, 100, 160);
        $second = $this->expense($booking, 11, 50, 70);
        $id = app(CreateNote::class)($booking, NoteType::Debit, [$first], 7);
        DB::table('fin_debit_notes')->where('id', $id)->update(['exw_expenses' => 3470, 'obs' => 'EXW digitado']);

        app(ChangeNoteItems::class)->add(NoteType::Debit, $id, $second);
        app(ChangeNoteItems::class)->remove(NoteType::Debit, $id, $first);

        $note = DB::table('fin_debit_notes')->find($id);
        $this->assertEquals(3470.0, $note->exw_expenses);
        $this->assertSame('EXW digitado', $note->obs);
    }

    public function test_an_exw_item_added_later_counts_in_the_profit_like_the_legacy(): void
    {
        $booking = $this->booking();
        $freight = $this->expense($booking, 10, 100, 160);
        $exw = $this->expense($booking, 20, 0, 90, ['flag_exw' => 1]);
        $id = app(CreateNote::class)($booking, NoteType::Debit, [$freight], 7);

        app(ChangeNoteItems::class)->add(NoteType::Debit, $id, $exw);

        $note = DB::table('fin_debit_notes')->find($id);
        $this->assertEquals([150.0, 0.0], [$note->gross_profit, $note->exw_expenses]);
    }

    public function test_items_are_stored_in_the_order_the_expenses_were_marked(): void
    {
        $booking = $this->booking();
        [$first, $second, $third] = [$this->expense($booking, 10, 1, 2), $this->expense($booking, 11, 1, 2), $this->expense($booking, 12, 1, 2)];

        $id = app(CreateNote::class)($booking, NoteType::Debit, [$third, $first, $second], 7);

        $this->assertEquals([$third, $first, $second], DB::table('fin_debit_note_items')->where('ref_debit_note', $id)->orderBy('id')->pluck('ref_expense')->all());
    }

    public function test_refuses_a_note_when_the_booking_has_no_agent(): void
    {
        $this->expectExceptionMessage('Não há agente cadastrado para esse embarque.');

        app(CreateNote::class)($this->booking(['pessoa_id_representante' => null]), NoteType::Debit, [], 7);
    }

    public function test_credit_prefix_and_agent_address_follow_the_legacy_creation_paths(): void
    {
        $import = $this->booking(['impor_expor' => 'i', 'pessoa_id_representante' => 3586]);
        $expense = $this->expense($import, 10, 1, 2);

        $empty = app(CreateNote::class)($import, NoteType::Credit, [], 7);
        $generated = app(CreateNote::class)($import, NoteType::Credit, [$expense], 7);

        $this->assertSame('IN', substr(DB::table('fin_credit_notes')->find($empty)->name, 0, 2));
        $this->assertStringContainsString('LUDENBERGER STRASSE 58', DB::table('fin_credit_notes')->find($empty)->obs);
        $this->assertSame('CN', substr(DB::table('fin_credit_notes')->find($generated)->name, 0, 2));
        $this->assertNull(DB::table('fin_credit_notes')->find($generated)->obs);
    }

    public function test_update_saves_the_values_and_puts_the_debit_back_on_the_current_agent(): void
    {
        $booking = $this->booking();
        $id = app(CreateNote::class)($booking, NoteType::Debit, [], 7);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['pessoa_id_representante' => 56]);

        app(UpdateNote::class)(NoteType::Debit, $id, ['handling' => 12.5, 'obs' => 'ajustada'], 7);

        $note = DB::table('fin_debit_notes')->find($id);
        $this->assertEquals([12.5, 'ajustada', 56], [$note->handling, $note->obs, (int) $note->ref_agente]);
        $this->assertSame(['Atualizado: Debit Note', 'u', 7], $this->lastTracking());
    }

    public function test_removing_a_note_deletes_its_items_and_keeps_the_justification_with_the_note_data(): void
    {
        $booking = $this->booking(['house_bl' => 'HBL9', 'invoice' => 'INV1', 'saida_efetiva' => '2026-10-20']);
        $id = app(CreateNote::class)($booking, NoteType::Debit, [$this->expense($booking, 10, 1, 2)], 7);

        app(RemoveNote::class)(NoteType::Debit, $id, 'lançada por engano', 7);

        $this->assertSame(0, DB::table('fin_debit_notes')->count() + DB::table('fin_debit_note_items')->count());
        $log = DB::table('rastreamento')->orderByDesc('id')->first();
        $this->assertSame(['Apagado: Debit Note', 'd', 'lançada por engano'], [$log->assunto, $log->evento, $log->justificativa]);
        $this->assertStringContainsString('<p>Debit Note: DN 0001/2026</p>', $log->historico);
        $this->assertStringContainsString('<p>HBL: HBL9</p>', $log->historico);
        $this->assertStringContainsString('<p>ETD: 20/10/2026</p>', $log->historico);
        $this->assertStringContainsString('<p>Tx.  Currency: US$ 5,5000</p>', $log->historico);
    }

    public function test_toggling_the_need_for_a_note_marks_the_booking_and_logs_the_justification(): void
    {
        $booking = $this->booking();

        $this->assertTrue(app(ToggleNoteNeed::class)(NoteType::Credit, $booking->getKey(), 'sem receita', 7));
        $this->assertSame('2026-11-05 10:00:00', $booking->fresh()->controle_credit);
        $marked = DB::table('rastreamento')->first();
        $this->assertSame(['Atualizado: Status Credit Note para SIM', 'y', 'sem receita'], [$marked->assunto, $marked->evento, $marked->justificativa]);
        $this->assertStringContainsString('<p>Necessidade de Nota: NÃO</p><p>Data: 05/11/2026 10:00</p>', $marked->historico);

        $this->assertFalse(app(ToggleNoteNeed::class)(NoteType::Credit, $booking->getKey(), 'voltou', 7));
        $this->assertNull($booking->fresh()->controle_credit);
        $this->assertSame('Atualizado: Status Credit Note para NÃO', DB::table('rastreamento')->orderByDesc('id')->value('assunto'));
    }

    /** @return list<float> lucro, devido à DMF e devido ao agente */
    private function amounts(string $table, int $id): array
    {
        $note = DB::table($table)->find($id);

        return [$note->gross_profit, $note->due_to_dmf, $note->due_to_dmf_agent];
    }

    /** @return array{string, string, int} */
    private function lastTracking(): array
    {
        $log = DB::table('rastreamento')->orderByDesc('id')->first();

        return [$log->assunto, $log->evento, (int) $log->created_by];
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'EXP1', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'pessoa_id_representante' => 55], ...$attributes]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(Embarque $booking, int $expenseId, float $buying, float $selling, array $attributes = []): int
    {
        return DB::table('fin_expenses_values')->insertGetId([...['ref_embarque' => $booking->getKey(), 'ref_expense' => $expenseId, 'buying_rate' => $buying, 'selling_rate' => $selling, 'flag_exw' => 0], ...$attributes]);
    }
}
