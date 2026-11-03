<?php

namespace Tests\Feature\Actions\VariedExpenses;

use App\Actions\VariedExpenses\BookingExpenseLines;
use App\Actions\VariedExpenses\DeleteVariedExpense;
use App\Actions\VariedExpenses\SaveVariedExpense;
use App\Models\Embarque;
use App\Models\FinDespesasVariadas;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class VariedExpenseActionsTest extends TestCase
{
    use LegacySchema;

    public function test_a_new_note_starts_open_and_not_sent(): void
    {
        $expense = app(SaveVariedExpense::class)($this->data());

        $this->assertSame([0, 0, 'BKG10'], [$expense->status_pagamento, $expense->status_enviado, $expense->fresh()->booking]);
    }

    public function test_editing_keeps_the_payment_status(): void
    {
        $expense = FinDespesasVariadas::create($this->data() + ['status_pagamento' => 1, 'data_pagamento' => '2026-10-01']);

        app(SaveVariedExpense::class)($this->data(['valor' => 99.5]), $expense);

        $this->assertSame([99.5, 1, '2026-10-01'], [$expense->fresh()->valor, $expense->fresh()->status_pagamento, $expense->fresh()->data_pagamento]);
    }

    public function test_the_note_file_is_attached_to_the_booking_by_note_type(): void
    {
        Storage::fake('tracking_uploads');
        $this->booking(10);

        app(SaveVariedExpense::class)($this->data(['tipo_nota' => '1']), null, 10, UploadedFile::fake()->create('nota 1.pdf', 10));
        app(SaveVariedExpense::class)($this->data(['tipo_nota' => '2']), null, 10, UploadedFile::fake()->create('nota 2.pdf', 10));

        $this->assertSame([35, 27], DB::table('emb_embarque_files')->where('ref_embarque', 10)->orderBy('id')->pluck('ref_file_type')->map(fn ($type) => (int) $type)->all());
        $this->assertCount(2, Storage::disk('tracking_uploads')->files('docs_emb'));
        $this->assertSame('Documento Anexo a Reserva', DB::table('cmn_files')->value('subject'));
    }

    public function test_the_note_file_falls_back_to_the_booking_with_the_same_text(): void
    {
        Storage::fake('tracking_uploads');
        $this->booking(10);

        app(SaveVariedExpense::class)($this->data(), null, null, UploadedFile::fake()->create('nota.pdf', 10));

        $this->assertSame(10, (int) DB::table('emb_embarque_files')->value('ref_embarque'));
    }

    public function test_a_note_file_without_a_booking_is_refused_and_nothing_is_saved(): void
    {
        Storage::fake('tracking_uploads');

        try {
            app(SaveVariedExpense::class)($this->data(), null, null, UploadedFile::fake()->create('nota.pdf', 10));
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('Reserva não encontrada para anexar a nota.', $e->getMessage());
        }

        $this->assertSame([0, 0], [FinDespesasVariadas::count(), DB::table('cmn_files')->count()]);
    }

    public function test_removing_unlinks_only_the_first_courier_with_the_invoice_as_note(): void
    {
        $expense = FinDespesasVariadas::create($this->data(['invoice' => 'INV1']));
        $first = $this->courier('INV1');
        $second = $this->courier('INV1');
        $other = $this->courier('OUTRA');

        app(DeleteVariedExpense::class)($expense);

        $link = fn (int $id) => DB::table('controle_courier')->where('controle_courier_id', $id)->value('status_vinculo_nota');
        $this->assertSame([null, 'v', 'v'], [$link($first), $link($second), $link($other)]);
        $this->assertSame(0, FinDespesasVariadas::count());
    }

    public function test_removing_without_invoice_does_not_touch_couriers_without_note(): void
    {
        $expense = FinDespesasVariadas::create($this->data(['invoice' => null]));
        $courier = $this->courier(null);

        app(DeleteVariedExpense::class)($expense);

        $this->assertSame('v', DB::table('controle_courier')->where('controle_courier_id', $courier)->value('status_vinculo_nota'));
    }

    public function test_bookings_search_lists_only_bookings_with_expenses(): void
    {
        $this->booking(10, 'ABC100');
        $this->booking(11, 'ABC200');
        DB::table('fin_expenses_values')->insert(['ref_embarque' => 10, 'ref_expense' => 1]);

        $found = app(BookingExpenseLines::class)->bookings('ABC');

        $this->assertSame(['ABC100'], $found->pluck('booking')->all());
    }

    public function test_lines_repeat_per_bl_and_skip_container_expenses_of_another_bl(): void
    {
        $this->booking(10);
        $blA = DB::table('bl')->insertGetId(['embarque_id' => 10, 'bl' => 'BLA']);
        $blB = DB::table('bl')->insertGetId(['embarque_id' => 10, 'bl' => 'BLB']);
        $container = DB::table('container')->insertGetId(['bl_id' => $blA, 'numero' => 'C1', 'volume' => '10']);
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dolar', 'symbol' => 'US$']]);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 1, 'name' => 'DMF']);
        DB::table('fin_expenses_unities')->insert([['id' => 1, 'name' => 'BL', 'acronym' => 'BL'], ['id' => 2, 'name' => 'Container', 'acronym' => 'CNT']]);
        DB::table('fin_expenses')->insert([['id' => 1, 'name' => 'FRETE', 'ref_unity' => 1], ['id' => 2, 'name' => 'THC', 'ref_unity' => 2]]);
        DB::table('fin_expenses_values')->insert([
            ['ref_embarque' => 10, 'ref_expense' => 1, 'buying_rate' => 100, 'ref_currency_br' => 2, 'selling_rate' => 150, 'ref_currency_sr' => 1, 'billing' => 'p', 'ref_due_to' => 1, 'ref_container' => null],
            ['ref_embarque' => 10, 'ref_expense' => 2, 'buying_rate' => 40, 'ref_currency_br' => 2, 'selling_rate' => 60, 'ref_currency_sr' => 1, 'billing' => 'c', 'ref_due_to' => null, 'ref_container' => $container],
        ]);

        $lines = app(BookingExpenseLines::class)(Embarque::find(10));

        $this->assertSame([
            ['BLA', 'FRETE / BL', 'US$ 100,00', 'R$ 150,00', 'PP', 'DMF'],
            ['BLB', 'FRETE / BL', 'US$ 100,00', 'R$ 150,00', 'PP', 'DMF'],
            ['BLA', 'THC / CNT', 'US$ 40,00', 'R$ 60,00', 'CC', ''],
        ], array_map('array_values', $lines));
    }

    /** @param array<string, mixed> $overrides */
    private function data(array $overrides = []): array
    {
        return $overrides + [
            'agente_id' => 5, 'cmn_currencies_id' => 2, 'data_despesa' => '2026-10-10', 'booking' => 'BKG10', 'hbl' => 'H1', 'mbl' => 'M1',
            'invoice' => 'INV', 'shipper' => 'Cliente', 'valor' => 10, 'impor_expor' => 'i', 'tipo_nota' => '2',
        ];
    }

    private function booking(int $id, ?string $booking = null): void
    {
        DB::table('embarque')->insert(['embarque_id' => $id, 'booking' => $booking ?? "BKG{$id}"]);
    }

    private function courier(?string $note): int
    {
        return DB::table('controle_courier')->insertGetId([
            'numero' => '1', 'data_recebimento' => '2026-10-01', 'tipo_courier_id' => 1, 'nota' => $note, 'status_vinculo_nota' => 'v', 'conta_utilizada' => 'x',
        ]);
    }
}
