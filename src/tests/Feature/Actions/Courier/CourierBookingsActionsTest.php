<?php

namespace Tests\Feature\Actions\Courier;

use App\Actions\Courier\AddCourierBooking;
use App\Actions\Courier\AddCourierName;
use App\Actions\Courier\CreateAgentExpense;
use App\Actions\Courier\RemoveCourierBooking;
use App\Models\ControleCourier;
use App\Models\FinDespesasVariadas;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CourierBookingsActionsTest extends TestCase
{
    use LegacySchema;

    public function test_links_a_booking_with_an_optional_note_and_the_same_booking_twice(): void
    {
        $courier = $this->courier();

        app(AddCourierBooking::class)($courier, 10, '');
        app(AddCourierBooking::class)($courier, 10, 'segunda');

        $this->assertSame([null, 'segunda'], $courier->bookings()->orderBy('ctr_courier_embarque_id')->pluck('observacao')->all());
    }

    public function test_a_linked_courier_refuses_new_bookings_names_and_booking_removal(): void
    {
        $open = $this->courier();
        $booking = app(AddCourierBooking::class)($open, 10, null);
        $open->update(['status_vinculo_nota' => 's']);

        foreach ([
            fn () => app(AddCourierBooking::class)($open->fresh(), 11, null),
            fn () => app(AddCourierName::class)($open->fresh(), 'Fulano'),
            fn () => app(RemoveCourierBooking::class)($booking->fresh()),
        ] as $call) {
            try {
                $call();
                $this->fail('Esperava DomainException.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, $open->bookings()->count());
        $this->assertNull(DB::table('ctr_courier_sem_embarque')->first());
    }

    public function test_removes_a_booking_and_adds_a_trimmed_name_while_open(): void
    {
        $courier = $this->courier();
        $booking = app(AddCourierBooking::class)($courier, 10, null);

        app(RemoveCourierBooking::class)($booking);
        app(AddCourierName::class)($courier, '  Fulano ');

        $this->assertSame([0, ['Fulano']], [$courier->bookings()->count(), $courier->names()->pluck('nome')->all()]);
    }

    public function test_creates_half_the_value_as_a_debit_note_for_the_agent_of_the_first_booking_and_locks_the_courier(): void
    {
        $this->booking(10, 'BKG10', 'HBL10', agent: 5);
        $this->booking(11, 'BKG11', 'HBL11', agent: 6);
        $courier = $this->courier(['nota' => 'N-77', 'valor' => 101]);
        $courier->bookings()->create(['embarque_id' => 10]);
        $courier->bookings()->create(['embarque_id' => 11]);

        $expense = app(CreateAgentExpense::class)($courier);

        $this->assertEquals([
            'agente_id' => 5, 'impor_expor' => 'i', 'tipo_nota' => '2', 'booking' => 'BKG10/BKG11', 'hbl' => 'HBL10 / HBL11',
            'invoice' => 'N-77', 'shipper' => 'COURRIER', 'cmn_currencies_id' => 2, 'valor' => 50.5, 'status_pagamento' => 0, 'status_enviado' => 0,
            'data_despesa' => now()->toDateString(),
        ], $expense->fresh()->only(['agente_id', 'impor_expor', 'tipo_nota', 'booking', 'hbl', 'invoice', 'shipper', 'cmn_currencies_id', 'valor', 'status_pagamento', 'status_enviado', 'data_despesa']));
        $this->assertSame('s', $courier->fresh()->status_vinculo_nota);
    }

    public function test_cuts_the_joined_booking_and_hbl_text_at_the_column_size_like_the_legacy(): void
    {
        $courier = $this->courier(['nota' => 'N-88', 'valor' => 10]);

        foreach (range(1, 30) as $id) {
            $this->booking($id, "BOOKING-LONGO-{$id}", "HBL-MUITO-LONGO-{$id}", agent: 5);
            $courier->bookings()->create(['embarque_id' => $id]);
        }

        $expense = app(CreateAgentExpense::class)($courier)->fresh();

        $this->assertSame(255, mb_strlen($expense->booking));
        $this->assertSame(255, mb_strlen($expense->hbl));
        $this->assertStringStartsWith('BOOKING-LONGO-1/BOOKING-LONGO-2/', $expense->booking);
    }

    public function test_refuses_without_creating_anything_when_the_courier_is_not_ready(): void
    {
        $this->booking(10, 'BKG10', 'HBL10', agent: 5);
        $this->booking(12, 'SEM', 'HBL12', agent: null);
        FinDespesasVariadas::create(['agente_id' => 1, 'cmn_currencies_id' => 2, 'valor' => 1, 'invoice' => 'JA']);

        $cases = [
            'sem nota' => [['nota' => null, 'valor' => 10], 10],
            'sem valor' => [['nota' => 'N-1', 'valor' => null], 10],
            'sem reserva' => [['nota' => 'N-1', 'valor' => 10], null],
            'primeira reserva sem agente' => [['nota' => 'N-1', 'valor' => 10], 12],
            'nota já lançada' => [['nota' => 'JA', 'valor' => 10], 10],
            'já vinculado' => [['nota' => 'N-1', 'valor' => 10, 'status_vinculo_nota' => 's'], 10],
        ];

        foreach ($cases as $label => [$attributes, $bookingId]) {
            $courier = $this->courier($attributes);
            $bookingId && $courier->bookings()->create(['embarque_id' => $bookingId]);

            try {
                app(CreateAgentExpense::class)($courier);
                $this->fail("Esperava recusar: {$label}.");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }

        }

        $this->assertSame(1, FinDespesasVariadas::count());
    }

    private function booking(int $id, string $booking, string $hbl, ?int $agent): void
    {
        DB::table('embarque')->insert(['embarque_id' => $id, 'booking' => $booking, 'house_bl' => $hbl, 'pessoa_id_representante' => $agent]);
    }

    private function courier(array $attributes = []): ControleCourier
    {
        return ControleCourier::create($attributes + ['numero' => 'N1', 'data_recebimento' => '2026-10-06', 'tipo_courier_id' => 1, 'conta_utilizada' => 'd']);
    }
}
