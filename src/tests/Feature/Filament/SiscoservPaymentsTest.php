<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\SiscoservPaymentsReport;
use App\Filament\Pages\SiscoservPayments;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SiscoservPaymentsTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    private function paid(string $booking, array $charge = [], array $shipment = []): void
    {
        $this->charge(['status' => 1, 'payment_date' => '2026-10-15', ...$charge], [
            'embarque_id' => crc32($booking) % 100000, 'booking' => $booking, 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'cliente_id' => 7, ...$shipment,
        ]);
    }

    /** @return list<string> */
    private function bookings(array $filters): array
    {
        return app(SiscoservPaymentsReport::class)($filters)->pluck('reserva')->all();
    }

    public function test_only_financial_opens_the_page(): void
    {
        $this->actingAs($this->user('manager'))->get(SiscoservPayments::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(SiscoservPayments::getUrl())->assertOk();
    }

    public function test_lists_only_paid_charges_of_maritime_imports(): void
    {
        $this->paid('OK');
        $this->paid('OPEN', ['status' => 0]);
        $this->paid('EXPO', shipment: ['impor_expor' => 'e']);
        $this->paid('AIR', shipment: ['tipo_embarque' => 'a']);

        $this->assertSame(['OK'], $this->bookings(['data_ini' => '2026-10-01']));
    }

    public function test_filters_by_payment_dates_and_client(): void
    {
        $this->paid('IN');
        $this->paid('BEFORE', ['payment_date' => '2026-09-30']);
        $this->paid('AFTER', ['payment_date' => '2026-11-01']);
        $this->paid('OTHER', shipment: ['cliente_id' => 8]);

        $this->assertSame(['IN', 'OTHER'], $this->bookings(['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31']));
        $this->assertSame(['IN', 'BEFORE', 'AFTER'], $this->bookings(['cliente_id' => 7]));
    }

    public function test_without_filters_nothing_is_listed(): void
    {
        $this->paid('IN');

        $this->assertSame([], $this->bookings([]));
    }

    public function test_shows_client_agent_and_office_names(): void
    {
        DB::table('pessoa')->insert([['pessoa_id' => 7, 'nome' => 'CLIENTE X'], ['pessoa_id' => 9, 'nome' => 'AGENTE Y']]);
        DB::table('escritorio')->insert(['escritorio_id' => 3, 'nome' => 'ESCRITORIO Z', 'nif' => '1']);
        $this->paid('IN', ['escritorio_id' => 3], ['pessoa_id_representante' => 9]);
        $this->actingAs($this->user());

        Livewire::test(SiscoservPayments::class)
            ->assertSee('Nenhum pagamento encontrado')
            ->set('tableDeferredFilters.busca.cliente_id', 7)
            ->call('applyTableFilters')
            ->assertSee(['CLIENTE X', 'AGENTE Y', 'IN', 'ESCRITORIO Z']);
    }
}
