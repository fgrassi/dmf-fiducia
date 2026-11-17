<?php

namespace Tests\Feature\Filament;

use App\Actions\Booking\Tracking\FillTrackingPhrase;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\TrackingRelationManager;
use App\Models\Embarque;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingTrackingTabTest extends TestCase
{
    use LegacySchema;

    public function test_adds_a_message_with_the_phrase_text_updates_eta_and_ship_and_applies_it_to_other_bookings(): void
    {
        $this->actingAs($user = $this->user());
        DB::table('navio')->insert(['navio_id' => 5, 'nome' => 'MSC ANNA']);
        DB::table('frase_categoria')->insert(['id' => 1, 'nome' => 'Booking']);
        DB::table('frase_transborda')->insert(['id' => 16, 'valor' => 'Booking made', 'frase' => 'Loaded on {NAVIO}', 'categoria_id' => 1]);
        $booking = $this->booking();
        $other = $this->booking();

        Livewire::test(TrackingRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), [
                'ref_frase_transborda' => 16,
                'descr' => 'Loaded',
                'data_reg' => '2026-11-10',
                'eta' => '2026-12-20',
                'navio_id_destino' => 5,
                'other_bookings' => [$other->getKey()],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame([$booking->getKey(), $other->getKey()], DB::table('transborda')->orderBy('transborda_id')->pluck('embarque_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
        $this->assertSame([$user->getKey()], DB::table('transborda')->pluck('usuario_id')->unique()->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['2026-12-20', 5], [substr($other->fresh()->chegada_prevista, 0, 10), (int) $other->fresh()->navio_id_destino]);
    }

    public function test_booking_made_to_chile_turns_on_the_chile_control(): void
    {
        $this->actingAs($this->user());
        DB::table('porto')->insert(['porto_id' => 9, 'nome' => 'Valparaiso', 'ref_pais' => 43]);
        DB::table('frase_transborda')->insert(['id' => 16, 'valor' => 'Booking made', 'frase' => 'x']);
        $booking = $this->booking(['porto_destino_id' => 9, 'ctrl_chile' => 0]);

        Livewire::test(TrackingRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['ref_frase_transborda' => 16, 'descr' => 'x', 'data_reg' => '2026-11-10'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, (int) $booking->fresh()->ctrl_chile);
    }

    public function test_edits_duplicates_and_deletes_but_leaves_system_messages_alone(): void
    {
        $this->actingAs($this->user());
        DB::table('frase_transborda')->insert([['id' => 20, 'valor' => 'Nota', 'frase' => 'n', 'categoria_id' => 10], ['id' => 21, 'valor' => 'Livre', 'frase' => 'l', 'categoria_id' => null]]);
        $booking = $this->booking();
        $mine = DB::table('transborda')->insertGetId(['embarque_id' => $booking->getKey(), 'descr' => 'antes', 'data_reg' => '2026-01-01', 'ref_frase_transborda' => 21]);
        $system = DB::table('transborda')->insertGetId(['embarque_id' => $booking->getKey(), 'descr' => 'nota', 'data_reg' => '2026-01-02', 'ref_frase_transborda' => 20]);

        $tab = Livewire::test(TrackingRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertTableActionHidden('edit', $system)
            ->assertTableActionHidden('delete', $system)
            ->callAction(TestAction::make('edit')->table($mine), ['ref_frase_transborda' => 21, 'descr' => 'depois', 'data_reg' => '2026-01-03'])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('duplicar')->table($mine))
            ->assertHasNoActionErrors();

        $this->assertSame('depois', DB::table('transborda')->where('transborda_id', $mine)->value('descr'));
        $this->assertSame(['depois'], DB::table('transborda')->where('transborda_id', '<>', $mine)->where('transborda_id', '<>', $system)->pluck('descr')->all());

        $tab->callAction(TestAction::make('delete')->table($mine))->assertHasNoActionErrors();
        $this->assertFalse(DB::table('transborda')->where('transborda_id', $mine)->exists());
    }

    public function test_removes_an_agent_contact_from_this_booking_only_and_logs_it(): void
    {
        $this->actingAs($user = $this->user());
        $booking = $this->booking(['pessoa_id_representante' => 55]);
        $other = $this->booking(['pessoa_id_representante' => 55]);
        DB::table('pessoa')->insert(['pessoa_id' => 55, 'nome' => 'AGENT CO']);
        DB::table('agente_contato')->insert([
            ['id' => 1, 'ref_pessoa' => 55, 'name' => 'Joe', 'email' => 'joe@agent.com', 'tipo' => 't', 'operacao' => 'e', 'created_at' => '2026-01-01'],
            ['id' => 2, 'ref_pessoa' => 56, 'name' => 'Outro agente', 'email' => 'x@agent.com', 'tipo' => 't', 'operacao' => 'e', 'created_at' => '2026-01-01'],
        ]);

        Livewire::test(TrackingRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('removerContatoAgente')->table(), ['contact' => 1])
            ->assertHasNoActionErrors()
            ->assertActionHidden(TestAction::make('removerContatoAgente')->table());

        $this->assertSame([[$booking->getKey(), 1]], DB::table('embarque_agente_contato_excluido')->get()->map(fn ($r) => [(int) $r->embarque_id, (int) $r->agente_contato_id])->all());
        $this->assertStringContainsString('Joe (joe@agent.com) do agente AGENT CO', DB::table('rastreamento')->where('ref_embarque', $booking->getKey())->value('historico'));
        Livewire::test(TrackingRelationManager::class, ['ownerRecord' => $other, 'pageClass' => ViewEmbarque::class])
            ->assertActionVisible(TestAction::make('removerContatoAgente')->table());
    }

    public function test_fills_the_phrase_markers_with_the_booking_data_and_keeps_the_missing_ones(): void
    {
        DB::table('navio')->insert(['navio_id' => 5, 'nome' => 'MSC ANNA']);
        DB::table('porto')->insert(['porto_id' => 9, 'nome' => 'Santos']);
        $booking = $this->booking(['navio_id' => 5, 'porto_origem_id' => 9, 'vg' => 'V12']);

        $this->assertSame('MSC ANNA V12 {NAVIO_FINAL} Santos {VIA}', app(FillTrackingPhrase::class)($booking, '{NAVIO} {VIAGEM} {NAVIO_FINAL} {PORTO_ORIGEM} {VIA}'));
    }

    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'EXP'.random_int(1000, 9999), 'impor_expor' => 'e', 'tipo_embarque' => 'm']));
    }

    private function user(): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.random_int(1, 99999), 'senha' => 'x', 'status' => 1, 'credentials' => 'booking']));
    }
}
