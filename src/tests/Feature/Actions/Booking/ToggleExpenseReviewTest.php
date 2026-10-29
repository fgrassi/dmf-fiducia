<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\ToggleExpenseReview;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ToggleExpenseReviewTest extends TestCase
{
    use LegacySchema;

    public function test_marks_the_booking_as_reviewed_and_undoes_it_logging_both(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = $this->booking();
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $booking->getKey(), 'ref_due_to' => 7]);

        $reviewed = app(ToggleExpenseReview::class)($booking, 7);

        $this->assertTrue($reviewed);
        $this->assertSame('s', $booking->fresh()->revisao_despesas);

        $reviewed = app(ToggleExpenseReview::class)($booking, 7);

        $this->assertFalse($reviewed);
        $this->assertSame('n', $booking->fresh()->revisao_despesas);
        $this->assertSame(
            ['Atualizado: Revisão de despesas', 'x', '2026-10-29', 7],
            [DB::table('rastreamento')->value('assunto'), DB::table('rastreamento')->value('evento'), DB::table('rastreamento')->value('created_at'), (int) DB::table('rastreamento')->value('created_by')],
        );
        $this->assertSame(
            ['<h3>Dados</h3> <p>Controle de revisão de despesas: status alerado para Revisado</p>', '<h3>Dados</h3> <p>Controle de revisão de despesas: status alerado para Não Revisado</p>'],
            DB::table('rastreamento')->orderBy('id')->pluck('historico')->all(),
        );
    }

    public function test_refuses_to_review_while_an_expense_has_no_due_to(): void
    {
        $booking = $this->booking();
        DB::table('fin_expenses_values')->insert([['ref_embarque' => $booking->getKey(), 'ref_due_to' => 7], ['ref_embarque' => $booking->getKey(), 'ref_due_to' => null]]);

        try {
            app(ToggleExpenseReview::class)($booking, 7);
            $this->fail('A revisão deveria ter sido recusada.');
        } catch (DomainException $e) {
            $this->assertSame('Todas as despesas necessitam estar com o campo "Due To" informado!', $e->getMessage());
            $this->assertSame('n', $booking->fresh()->revisao_despesas);
            $this->assertSame(0, DB::table('rastreamento')->count());
        }
    }

    public function test_undoing_a_review_does_not_check_due_to(): void
    {
        $booking = $this->booking(['revisao_despesas' => 's']);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $booking->getKey(), 'ref_due_to' => null]);

        $this->assertFalse(app(ToggleExpenseReview::class)($booking, 7));
    }

    public function test_only_maritime_imports_have_the_review_control(): void
    {
        $this->assertTrue(ToggleExpenseReview::applies((new Embarque)->forceFill(['impor_expor' => 'i', 'tipo_embarque' => 'm'])));
        $this->assertFalse(ToggleExpenseReview::applies((new Embarque)->forceFill(['impor_expor' => 'e', 'tipo_embarque' => 'm'])));
        $this->assertFalse(ToggleExpenseReview::applies((new Embarque)->forceFill(['impor_expor' => 'i', 'tipo_embarque' => 'a'])));
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'IMP1', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'revisao_despesas' => 'n'], ...$attributes]));
    }
}
