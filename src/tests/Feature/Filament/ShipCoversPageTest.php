<?php

namespace Tests\Feature\Filament;

use App\Actions\ShipCover\ShipCoverPdf;
use App\Filament\Pages\ShipCovers;
use App\Models\Embarque;
use App\Models\Navio;
use App\Models\Porto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ShipCoversPageTest extends TestCase
{
    use LegacySchema;

    private int $ship;

    private int $origin;

    private int $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'import'])));
        $this->ship = DB::table('navio')->insertGetId(['nome' => 'MSC Ana']);
        $this->origin = DB::table('porto')->insertGetId(['nome' => 'Xangai']);
        $this->destination = DB::table('porto')->insertGetId(['nome' => 'Santos']);
    }

    public function test_lists_only_the_open_bookings_of_the_ship_and_route_and_needs_the_three_filters(): void
    {
        $open = $this->booking('BKG-ABERTO');
        $this->booking('BKG-CANCELADO', ['finalizado' => 'c']);
        $this->booking('BKG-SAIU', ['saida_efetiva' => '2026-11-01']);
        $this->booking('BKG-ANTIGO', ['dteCadastro' => '2020-01-01']);
        $this->booking('BKG-OUTRO-NAVIO', ['navio_id' => DB::table('navio')->insertGetId(['nome' => 'Outro'])]);

        Livewire::test(ShipCovers::class)
            ->assertCountTableRecords(0)
            ->set('tableFilters.capa', ['navio' => $this->ship, 'porto_origem' => $this->origin])
            ->assertCountTableRecords(0)
            ->set('tableFilters.capa', ['navio' => $this->ship, 'porto_origem' => $this->origin, 'porto_destino' => $this->destination])
            ->assertCanSeeTableRecords([$open])
            ->assertCountTableRecords(1);
    }

    public function test_pdf_is_generated_for_the_selected_bookings(): void
    {
        $booking = $this->booking('BKG-ABERTO');

        $pdf = app(ShipCoverPdf::class)->render(Navio::find($this->ship), Porto::find($this->origin), Porto::find($this->destination), Embarque::whereKey($booking->getKey())->get());

        $text = $pdf->Output('', 'S');
        $this->assertStringStartsWith('%PDF', $text);
    }

    private function booking(string $name, array $overrides = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId([
            'booking' => $name,
            'navio_id' => $this->ship,
            'porto_origem_id' => $this->origin,
            'porto_destino_id' => $this->destination,
            'finalizado' => 'n',
            'dteCadastro' => now()->toDateString(),
            ...$overrides,
        ]));
    }
}
