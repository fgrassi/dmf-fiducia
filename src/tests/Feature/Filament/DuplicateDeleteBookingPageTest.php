<?php

namespace Tests\Feature\Filament;

use App\Actions\Booking\Creation\CreateBooking;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BookingCreationFixture;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DuplicateDeleteBookingPageTest extends TestCase
{
    use BookingCreationFixture;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBookingFixture();
        $this->actingAs($this->author);
    }

    public function test_duplicating_an_import_opens_the_copy(): void
    {
        $booking = $this->booking();

        $page = Livewire::test(ViewEmbarque::class, ['record' => $booking->getKey()])
            ->callAction('duplicar')
            ->assertHasNoActionErrors();

        $copy = Embarque::where('booking', 'Cópia IMP1-26')->sole();
        $page->assertRedirect(EmbarqueResource::getUrl('view', ['record' => $copy]));
    }

    public function test_duplicating_a_maritime_export_asks_for_the_freight_invoice(): void
    {
        $booking = $this->booking();
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['impor_expor' => 'e']);

        Livewire::test(ViewEmbarque::class, ['record' => $booking->getKey()])
            ->callAction('duplicar', ['flag_fi' => 1])
            ->assertHasActionErrors(['pessoa_id_pagador' => 'required', 'recebedor_freight_invoice' => 'required'])
            ->setActionData(['flag_fi' => 0])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(2, Embarque::count());
    }

    public function test_deleting_needs_a_justification_and_goes_back_to_the_list(): void
    {
        $booking = $this->booking();

        Livewire::test(ViewEmbarque::class, ['record' => $booking->getKey()])
            ->callAction('excluir')
            ->assertHasActionErrors(['justificativa' => 'required'])
            ->setActionData(['justificativa' => 'Cadastrada por engano'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertRedirect(EmbarqueResource::getUrl('index'));

        $this->assertSame(0, Embarque::count());
    }

    public function test_a_blocked_deletion_stays_on_the_booking_and_says_why(): void
    {
        $booking = $this->booking();
        $this->row('fin_expenses_values', ['ref_embarque' => $booking->getKey()]);

        Livewire::test(ViewEmbarque::class, ['record' => $booking->getKey()])
            ->callAction('excluir', ['justificativa' => 'Engano'])
            ->assertNotified('Não foi possível excluir a reserva. Há registros ligados a ela: Despesas.')
            ->assertNoRedirect();

        $this->assertSame(1, Embarque::count());
    }

    private function booking(): Embarque
    {
        return app(CreateBooking::class)($this->bookingData(), 'i', 'm', $this->author);
    }
}
