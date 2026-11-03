<?php

namespace Tests\Feature\Actions\BoxControl;

use App\Actions\BoxControl\AddBookingToBox;
use App\Actions\BoxControl\BoxLabelPdf;
use App\Actions\BoxControl\CloseBox;
use App\Actions\BoxControl\RemoveBookingFromBox;
use App\Models\ControleCaixa;
use App\Models\ControleCaixaEmbarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BoxActionsTest extends TestCase
{
    use LegacySchema;

    public function test_adding_a_booking_stores_it_and_marks_the_booking_as_finished(): void
    {
        $box = $this->box();
        $this->booking(10);

        $item = app(AddBookingToBox::class)($box, 10, 'Original');

        $this->assertSame(['id' => $box->getKey(), 'notes' => 'Original'], ['id' => $item->controle_caixa_id, 'notes' => $item->obs]);
        $this->assertSame('e', DB::table('embarque')->where('embarque_id', 10)->value('finalizado'));
        $this->assertSame('a', $box->fresh()->status);
    }

    public function test_adding_the_last_booking_of_the_limit_closes_the_box(): void
    {
        $box = $this->box();
        $this->fill($box, 49);
        $this->booking(10);

        app(AddBookingToBox::class)($box, 10);

        $this->assertSame('f', $box->fresh()->status);
    }

    public function test_miscellaneous_boxes_hold_110_bookings(): void
    {
        $box = $this->box(['tipo_caixa' => 'd']);
        $this->fill($box, 50);
        $this->booking(10);

        app(AddBookingToBox::class)($box, 10);

        $this->assertSame('a', $box->fresh()->status);
    }

    public function test_a_closed_box_does_not_accept_bookings(): void
    {
        $box = $this->box(['status' => 'f']);
        $this->booking(10);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Caixa Cheia. Embarque Não Incluído.');

        try {
            app(AddBookingToBox::class)($box, 10);
        } finally {
            $this->assertSame(0, ControleCaixaEmbarque::count());
        }
    }

    public function test_a_booking_already_in_another_box_is_refused_and_left_untouched(): void
    {
        $other = $this->box();
        $this->booking(10, ['finalizado' => 'n']);
        $other->bookings()->create(['embarque_id' => 10]);

        try {
            app(AddBookingToBox::class)($this->box(), 10);
            $this->fail('A reserva já estava em outra caixa.');
        } catch (DomainException $e) {
            $this->assertSame('Embarque já incluído em outra Caixa.', $e->getMessage());
        }

        $this->assertSame(1, ControleCaixaEmbarque::count());
        $this->assertSame('n', DB::table('embarque')->where('embarque_id', 10)->value('finalizado'));
    }

    public function test_removing_a_booking_reopens_a_box_below_its_limit(): void
    {
        $box = $this->box(['status' => 'f']);
        $this->fill($box, 50);

        app(RemoveBookingFromBox::class)($box->bookings()->first());

        $this->assertSame(49, $box->bookings()->count());
        $this->assertSame('a', $box->fresh()->status);
    }

    public function test_removing_a_booking_keeps_a_miscellaneous_box_closed_while_it_is_still_full(): void
    {
        $box = $this->box(['tipo_caixa' => 'd', 'status' => 'f']);
        $this->fill($box, 111);

        app(RemoveBookingFromBox::class)($box->bookings()->first());

        $this->assertSame('f', $box->fresh()->status);
    }

    public function test_closing_a_box_before_the_limit(): void
    {
        $box = $this->box();

        app(CloseBox::class)($box);

        $this->assertSame('f', $box->fresh()->status);
    }

    public function test_label_pdf_shows_the_box_and_lists_its_bookings(): void
    {
        $box = $this->box(['nome_caixa' => 'IMPO 2026']);
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente SA']);
        $this->booking(10, ['booking' => 'BKG10', 'house_bl' => 'HBL10', 'cliente_id' => 5]);
        $box->bookings()->create(['embarque_id' => 10]);

        $document = app(BoxLabelPdf::class)->render($box);
        $document->setCompression(false);
        $pdf = $document->Output('', 'S');

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString("({$box->getKey()})", $pdf);

        foreach (['DMF LOGISTICS DO BRASIL', 'IMPO 2026', 'BKG10', 'HBL10', 'Cliente SA'] as $text) {
            $this->assertStringContainsString("({$text})", $pdf);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function box(array $attributes = []): ControleCaixa
    {
        return ControleCaixa::create($attributes + ['nome_caixa' => 'CX', 'tipo_caixa' => 'i', 'status' => 'a']);
    }

    /** @param array<string, mixed> $attributes */
    private function booking(int $id, array $attributes = []): void
    {
        DB::table('embarque')->insert(['embarque_id' => $id, 'booking' => "BKG{$id}"] + $attributes);
    }

    private function fill(ControleCaixa $box, int $count): void
    {
        DB::table('controle_caixa_embarque')->insert(array_map(fn (int $i) => ['embarque_id' => 1000 + $i, 'controle_caixa_id' => $box->getKey()], range(1, $count)));
    }
}
