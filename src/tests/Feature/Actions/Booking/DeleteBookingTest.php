<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\Creation\CreateBooking;
use App\Actions\Booking\DeleteBooking;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BookingCreationFixture;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DeleteBookingTest extends TestCase
{
    use BookingCreationFixture;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBookingFixture();
        Storage::fake('tracking_uploads');
    }

    public function test_deletes_the_booking_with_what_belongs_to_it_and_warns_the_department_with_the_justification(): void
    {
        $booking = $this->booking();
        $other = $this->booking(['booking' => 'IMP2-26']);
        $bl = DB::table('bl')->where('embarque_id', $booking->getKey())->value('bl_id');
        $this->row('container', ['bl_id' => $bl]);
        $this->row('cmn_daily_booking_report', ['ref_booking' => $booking->getKey()]);
        $this->file($booking, 'docs_emb/solo.pdf');

        $this->remove($booking, 'Cadastro <duplicado>');

        $this->assertDatabaseMissing('embarque', ['embarque_id' => $booking->getKey()]);

        foreach (['mercadoria', 'bl', 'embarque_contato_cliente'] as $table) {
            $this->assertSame([$other->getKey()], DB::table($table)->pluck('embarque_id')->unique()->map(fn ($id) => (int) $id)->values()->all(), $table);
        }

        $this->assertSame(0, DB::table('rastreamento')->where('ref_embarque', $booking->getKey())->count());
        $this->assertSame([0, 0, 0], [DB::table('container')->count(), DB::table('cmn_daily_booking_report')->count(), DB::table('cmn_files')->count()]);
        Storage::disk('tracking_uploads')->assertMissing('docs_emb/solo.pdf');
        $this->assertSame(['[Aviso] Reserva Excluída - IMP1-26'], $this->sentSubjects()->all());
        $this->assertSame(['importacao@dmflog.com.br'], $this->sentRecipients());
        $this->assertStringContainsString('Cadastro &lt;duplicado&gt;', $this->sentHtml());
    }

    public function test_keeps_the_files_that_another_record_still_uses(): void
    {
        $booking = $this->booking();
        $other = $this->booking(['booking' => 'IMP2-26']);
        $shared = $this->file($booking, 'docs_emb/compartilhado.pdf');
        $this->row('emb_embarque_files', ['ref_embarque' => $other->getKey(), 'ref_file' => $shared, 'ref_file_type' => 1]);
        $this->file($booking, 'docs_emb/mesmo_nome.pdf');
        $this->file($other, 'docs_emb/mesmo_nome.pdf');

        $this->remove($booking);

        $this->assertDatabaseHas('cmn_files', ['id' => $shared]);
        $this->assertSame(2, DB::table('emb_embarque_files')->where('ref_embarque', $other->getKey())->count());
        Storage::disk('tracking_uploads')->assertExists(['docs_emb/compartilhado.pdf', 'docs_emb/mesmo_nome.pdf']);
    }

    #[DataProvider('links')]
    public function test_a_linked_record_blocks_the_deletion_before_anything_is_removed(string $table, string $column, array $extra, string $label): void
    {
        $booking = $this->booking();
        $this->row($table, [...$extra, $column => $booking->getKey()]);

        try {
            $this->remove($booking);
            $this->fail('A reserva foi excluída.');
        } catch (DomainException $e) {
            $this->assertStringContainsString($label, $e->getMessage());
        }

        $this->assertDatabaseHas('mercadoria', ['embarque_id' => $booking->getKey()]);
        $this->assertDatabaseHas('rastreamento', ['ref_embarque' => $booking->getKey()]);
        $this->assertDatabaseHas('bl', ['embarque_id' => $booking->getKey()]);
        $this->assertSame([], $this->sentSubjects()->all());
    }

    public static function links(): array
    {
        return [
            'despesa' => ['fin_expenses_values', 'ref_embarque', [], 'Despesas'],
            'cobrança' => ['fin_charges', 'ref_booking', ['ref_type' => 1, 'created_at' => '2026-01-01'], 'Cobranças'],
            'seguro vinculado' => ['embarque', 'reserva_vinculada_id', [], 'Reserva de seguro vinculada'],
            'controle PA' => ['controle_pa', 'embarque_id', [], 'Controle PA'],
        ];
    }

    public function test_an_order_of_another_booking_pointing_to_one_of_its_bls_blocks_the_deletion(): void
    {
        $booking = $this->booking();
        $bl = DB::table('bl')->where('embarque_id', $booking->getKey())->value('bl_id');
        $this->row('fin_payment_order_items', ['ref_payment_order' => 1, 'ref_expense' => 1, 'ref_currency' => 1, 'created_at' => '2026-01-01', 'ref_bl' => $bl]);

        $this->expectExceptionMessage('Ordens de pagamento');

        $this->remove($booking);
    }

    #[DataProvider('quotations')]
    public function test_the_quotation_left_without_bookings_goes_back_to_created_unless_finalized(int $before, int $after): void
    {
        $quotation = $this->row('cotacao_expo', ['pessoa_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'transit_time' => 1, 'armador_id' => 1, 'validade' => '2027-01-01', 'status' => $before]);
        $booking = $this->booking();
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['cotacao_expo_id' => $quotation]);

        $this->remove($booking);

        $this->assertSame($after, (int) DB::table('cotacao_expo')->value('status'));
    }

    public static function quotations(): array
    {
        return ['associada' => [1, 0], 'finalizada' => [2, 2]];
    }

    public function test_needs_a_justification_and_a_user_of_the_operation(): void
    {
        $booking = $this->booking();

        try {
            $this->remove($booking, '   ');
            $this->fail('A reserva foi excluída.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('justificativa', $e->getMessage());
        }

        DB::table('usuario')->where('usuario_id', $this->author->getKey())->update(['credentials' => 'booking,export']);
        $this->author->refresh();

        $this->expectExceptionMessage('desta operação');
        $this->remove($booking);
    }

    private function booking(array $overrides = []): Embarque
    {
        $booking = app(CreateBooking::class)(
            $this->bookingData(['goods' => [['nome' => 'Peças', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0]], ...$overrides]),
            'i',
            'm',
            $this->author,
        );

        return $booking;
    }

    private function file(Embarque $booking, string $path): int
    {
        Storage::disk('tracking_uploads')->put($path, 'x');
        $file = $this->row('cmn_files', ['name' => basename($path)]);
        $this->row('emb_embarque_files', ['ref_embarque' => $booking->getKey(), 'ref_file' => $file, 'ref_file_type' => 1]);

        return $file;
    }

    private function remove(Embarque $booking, string $justification = 'Erro de cadastro'): void
    {
        app(DeleteBooking::class)($booking, $justification, $this->author);
    }
}
