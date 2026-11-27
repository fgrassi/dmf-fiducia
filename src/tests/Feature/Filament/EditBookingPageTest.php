<?php

namespace Tests\Feature\Filament;

use App\Actions\Booking\Creation\CreateBooking;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\Pages\EditEmbarque;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BookingCreationFixture;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class EditBookingPageTest extends TestCase
{
    use BookingCreationFixture;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBookingFixture();
        DB::table('paises')->insert([['paises_id' => 1, 'nome' => 'BRASIL'], ['paises_id' => 10, 'nome' => 'CHINA'], ['paises_id' => 25, 'nome' => 'ÁFRICA DO SUL']]);
        $this->actingAs($this->author);
    }

    #[DataProvider('types')]
    public function test_each_booking_type_opens_filled_and_saves_without_changing_anything(string $operation, string $mode): void
    {
        $booking = $this->booking($operation, $mode);
        $before = $this->stored($booking);

        Livewire::test(EditEmbarque::class, ['record' => $booking->getKey()])
            ->assertSchemaStateSet(['booking' => $booking->booking, 'cliente_id' => $booking->cliente_id])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(EmbarqueResource::getUrl('view', ['record' => $booking]));

        $this->assertEquals($before, $this->stored($booking));
    }

    public static function types(): array
    {
        return [
            'importação marítima' => ['i', 'm'],
            'exportação marítima' => ['e', 'm'],
            'importação aérea' => ['i', 'a'],
            'exportação aérea' => ['e', 'a'],
        ];
    }

    public function test_an_old_booking_with_values_that_left_the_lists_still_saves(): void
    {
        $booking = $this->booking('i', 'm');
        $goneSeller = $this->row('vendedor', ['nome' => 'Saiu', 'deleted_at' => '2025-01-01']);
        $broker = $this->row('despachante', ['nome' => 'SEM VINCULO']);
        $airEquipment = $this->row('tipo_container', ['nome' => 'ULD', 'tipo_embarque' => 'a']);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update([
            'vendedor_id' => $goneSeller, 'despachante_id' => $broker, 'de' => 'Quem saiu', 'armador_id' => 0,
            'incoterm_id' => 0, 'tipo_frete' => '', 'garantee' => '', 'chegada_desejada' => '0000-00-00',
            'insurance' => '', 'inland' => 0, 'ctr_redestinacao' => 0,
        ]);
        DB::table('mercadoria')->where('embarque_id', $booking->getKey())->update(['tipo_container_id' => $airEquipment, 'quantidade' => '1,5', 'quimico' => 2, 'carga_perigosa' => 2]);
        // Como o legado deixa: contato repetido, ETA antes do ETD, carga perigosa sem "Químico?" e prazo com segundos.
        DB::table('embarque_contato_cliente')->insert([
            ['embarque_id' => $booking->getKey(), 'usuario_id' => $this->contact],
            ['embarque_id' => $booking->getKey(), 'usuario_id' => 99999],
        ]);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['chegada_prevista' => '2026-11-01', 'dead_line_imo' => '2026-12-02 12:00:00']);
        $this->row('mercadoria', ['embarque_id' => $booking->getKey(), 'nome' => 'Tinta', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0, 'carga_perigosa' => 1]);
        $before = $this->stored($booking);

        Livewire::test(EditEmbarque::class, ['record' => $booking->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals($before, $this->stored($booking));
    }

    public function test_the_view_page_offers_editing_and_a_cancelled_booking_cannot_be_edited(): void
    {
        $booking = $this->booking('i', 'm');

        Livewire::test(ViewEmbarque::class, ['record' => $booking->getKey()])->assertActionVisible('edit');

        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['finalizado' => 'c']);

        Livewire::test(ViewEmbarque::class, ['record' => $booking->getKey()])->assertActionHidden('edit');
        $this->get(EmbarqueResource::getUrl('edit', ['record' => $booking]))->assertForbidden();
    }

    private function booking(string $operation, string $mode): Embarque
    {
        $export = $operation === 'e';
        $equipment = $mode === 'm' ? $this->equipment : $this->row('tipo_container', ['nome' => 'PALLET', 'tipo_embarque' => 'a']);

        return app(CreateBooking::class)($this->bookingData([
            'booking' => $export ? 'EXP-1' : ($mode === 'a' ? 'AIMP1-26' : 'IMP1-26'),
            'de' => 'Ana',
            'saida_prevista' => '2026-12-01',
            'chegada_prevista' => '2026-12-31',
            'contacts' => ['cliente' => [$export ? $this->contactOf($this->client, 3) : $this->contact]],
            'goods' => [['nome' => 'Peças', 'quantidade' => 2, 'tipo_container_id' => $equipment, 'quimico' => 0, 'gross_weight' => 10]],
            ...($export && $mode === 'm' ? [
                'insurance' => null, 'tipo_fechamento' => 'o', 'armador_id' => $this->row('armador', ['nome' => 'MSC']), 'via' => 'Direto',
                'free_time_detention' => 7, 'data_validade' => '2027-01-31', 'consolidado' => 'n', 'instrucao_especial' => 'Não empilhar',
            ] : []),
        ]), $operation, $mode, $this->author);
    }

    /** Reserva, mercadorias e contatos como estão no banco. */
    private function stored(Embarque $booking): array
    {
        return [
            'booking' => (array) DB::table('embarque')->where('embarque_id', $booking->getKey())->first(),
            'goods' => DB::table('mercadoria')->where('embarque_id', $booking->getKey())->get()->map(fn (object $row) => (array) $row)->all(),
            'contacts' => DB::table('embarque_contato_cliente')->where('embarque_id', $booking->getKey())->get()->map(fn (object $row) => (array) $row)->all(),
        ];
    }
}
