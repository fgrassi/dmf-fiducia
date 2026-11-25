<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\ArrivalConfirmationsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\DepartureConfirmationsRelationManager;
use App\Models\Embarque;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingConfirmationTabTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'booking'])));
        DB::table('frase_transborda')->insert([
            ['id' => 82, 'valor' => 'Embarque', 'frase' => 'x', 'categoria_id' => 10],
            ['id' => 110, 'valor' => 'Atracação', 'frase' => 'x', 'categoria_id' => 11],
        ]);
    }

    public function test_confirms_the_departure_from_the_tab_and_deletes_the_message(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'a']);
        $tab = Livewire::test(DepartureConfirmationsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class]);

        $tab->callAction(TestAction::make('create')->table(), ['data_reg' => '2026-11-10', 'saida_efetiva' => '2026-11-09'])
            ->assertHasNoActionErrors()
            ->assertSee(['Confirmado por Ana', 'Flight confirmed']);

        $this->assertSame('2026-11-09', substr($booking->fresh()->saida_efetiva, 0, 10));

        $tab->callAction(TestAction::make('delete')->table(DB::table('transborda')->value('transborda_id')))->assertHasNoActionErrors();

        $this->assertNull($booking->fresh()->saida_efetiva);
        $this->assertSame(0, DB::table('departure_confirmation')->count());
    }

    public function test_a_refused_departure_shows_the_reason_and_saves_nothing(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);

        Livewire::test(DepartureConfirmationsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['data_reg' => '2026-11-10', 'saida_efetiva' => '2026-11-09'])
            ->assertNotified('Não há armador ou porto de origem cadastrado para esta reserva.');

        $this->assertSame(0, DB::table('transborda')->count());
    }

    public function test_import_arrival_asks_for_the_type_and_the_voyage(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'm']);

        Livewire::test(ArrivalConfirmationsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['kind' => 1, 'data_reg' => '2026-11-10', 'dtePrevista' => '2026-11-10 09:00:00'])
            ->assertHasActionErrors(['vg' => 'required']);

        Livewire::test(ArrivalConfirmationsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['kind' => 1, 'vg' => 'V1', 'data_reg' => '2026-11-10', 'dtePrevista' => '2026-11-10 09:00:00'])
            ->assertHasNoActionErrors()
            ->assertSee('ATRACAÇÃO DE NAVIO');

        $this->assertSame('s', $booking->fresh()->finalizado);
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'BKG1', 'cliente_id' => 1]));
    }
}
