<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\ImoRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\MailingsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\OriginDocsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\RouteRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\ScheduleRelationManager;
use App\Models\Embarque;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingScheduleRouteImoMailTabsTest extends TestCase
{
    use LegacySchema;

    public function test_schedule_saves_the_cut_off_and_logs_the_bilingual_message_without_touching_the_departure(): void
    {
        $this->actingAs($this->user());
        DB::table('navio')->insert(['navio_id' => 5, 'nome' => 'MSC ANNA']);
        $booking = $this->booking(['navio_id' => 5, 'vg' => 'V1', 'saida_prevista' => '2026-12-01', 'chegada_prevista' => '2026-12-30', 'saida_efetiva' => '2026-12-02']);

        Livewire::test(ScheduleRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['altera' => true, 'data_reg' => '2026-11-10', 'cutt_off' => '2026-11-25 14:30', 'descr' => 'Obs'])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-11-25 14:30:00', $booking->fresh()->cutt_off);
        $this->assertSame('2026-12-02', substr($booking->fresh()->saida_efetiva, 0, 10));
        $message = DB::table('transborda')->where('ref_frase_transborda', 27)->value('descr');
        $this->assertStringContainsString('BOOKING ALTERADO CONFORME ABAIXO:', $message);
        $this->assertStringContainsString('- Cutt Off: 25/Nov/2026 14:30', $message);
        $this->assertStringContainsString('BOOKING CHANGED AS PER BELOW:', $message);
        $this->assertStringContainsString('- Cutt Off: Nov/25/2026 14:30', $message);
        $this->assertStringEndsWith('Obs', $message);
    }

    public function test_route_logs_the_change_in_tracking_alerts_for_large_ships_and_deletes_with_its_messages(): void
    {
        $this->actingAs($this->user());
        DB::table('navio')->insert(['navio_id' => 5, 'nome' => 'MEGA', 'tamanho' => 400]);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos'], ['porto_id' => 2, 'nome' => 'Rotterdam']]);
        $booking = $this->booking();

        $tab = Livewire::test(RouteRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['navio_id' => 5, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'vg' => 'V9', 'saida_prevista' => '2026-12-01', 'chegada_prevista' => '2026-12-21', 'data_reg' => '2026-11-10'])
            ->assertHasNoActionErrors();

        $route = DB::table('embarque_rota')->first();
        $tracking = DB::table('transborda')->where('ref_frase_transborda', 105)->first();
        $this->assertSame((int) $route->rota_id, (int) $tracking->rota_id);
        $this->assertStringContainsString('- Porto de Destino: Rotterdam', $tracking->descr);
        $this->assertSame(['[Aviso] Navio com mais de 300 metros de comprimento'], $this->sentSubjects()->all());

        $tab->callAction(TestAction::make('delete')->table($route->rota_id))->assertHasNoActionErrors();

        $this->assertSame([0, 0], [DB::table('embarque_rota')->count(), DB::table('transborda')->count()]);
    }

    public function test_imo_registers_the_shipment_then_the_confirmation_with_file_and_the_status(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($this->user());
        $booking = $this->booking(['impor_expor' => 'i']);

        $tab = Livewire::test(ImoRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['envio_imo_armador' => '2026-11-01'])
            ->assertHasNoActionErrors()
            ->assertActionHidden(TestAction::make('create')->table())
            ->callAction(TestAction::make('status')->table(), ['controle_imo' => 's']);

        $imo = DB::table('controle_imo_ci')->first();
        $tab->callAction(TestAction::make('edit')->table($imo->controle_imo_id), [
            'envio_imo_armador' => '2026-11-01',
            'confirmacao_imo_armador' => '2026-11-05',
            'arquivo' => UploadedFile::fake()->create('../ok.php.pdf', 10, 'application/pdf'),
        ])->assertHasNoActionErrors();

        $this->assertSame('s', $booking->fresh()->controle_imo);
        $imo = DB::table('controle_imo_ci')->first();
        $this->assertMatchesRegularExpression("/^emb_{$booking->getKey()}_[A-Za-z0-9]{16}\.pdf$/", $imo->ref_file_confirmacao_imo_armador);
        Storage::disk('tracking_uploads')->assertExists("imo/{$imo->ref_file_confirmacao_imo_armador}");

        $tab->callAction(TestAction::make('removerArquivo')->table($imo->controle_imo_id));
        $imo = DB::table('controle_imo_ci')->first();
        $this->assertSame([null, null], [$imo->ref_file_confirmacao_imo_armador, $imo->confirmacao_imo_armador]);
    }

    public function test_imo_only_on_imports_and_these_tabs_hide_for_suspended_and_cancelled_bookings(): void
    {
        $export = $this->booking(['impor_expor' => 'e']);
        $air = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'a']);
        $suspended = $this->booking(['impor_expor' => 'i', 'finalizado' => 'p']);
        $open = $this->booking(['impor_expor' => 'i', 'finalizado' => 'n']);

        $this->assertFalse(ImoRelationManager::canViewForRecord($export, ViewEmbarque::class));
        $this->assertFalse(ImoRelationManager::canViewForRecord($air, ViewEmbarque::class));
        $this->assertFalse(ImoRelationManager::canViewForRecord($suspended, ViewEmbarque::class));
        $this->assertFalse(RouteRelationManager::canViewForRecord($suspended, ViewEmbarque::class));
        $this->assertTrue(ImoRelationManager::canViewForRecord($open, ViewEmbarque::class));
        $this->assertTrue(MailingsRelationManager::canViewForRecord($open, ViewEmbarque::class));
    }

    public function test_mail_tab_lists_only_the_shipments_linked_to_the_booking(): void
    {
        $this->actingAs($this->user());
        DB::table('estados')->insert(['estados_id' => 3, 'nome' => 'Rio Grande do Sul']);
        $booking = $this->booking();
        $mine = DB::table('controle_correio')->insertGetId(['data_envio' => '2026-11-01', 'destinatario' => 'ACME', 'estados_id' => 3, 'num_sedex' => 'SS123']);
        $other = DB::table('controle_correio')->insertGetId(['data_envio' => '2026-11-02', 'destinatario' => 'OUTRO', 'estados_id' => 3, 'num_sedex' => 'SS999']);
        DB::table('ctr_correio_embarque')->insert(['embarque_id' => $booking->getKey(), 'controle_correio_id' => $mine]);

        Livewire::test(MailingsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('Rio Grande do Sul');
    }

    public function test_origin_docs_creates_the_release_when_missing_and_marks_a_pending_one_as_sent(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $fresh = $this->booking();
        $pending = $this->booking();
        DB::table('liberacao_embarque_origem')->insert(['embarque_id' => $pending->getKey(), 'status' => 0]);

        Livewire::test(OriginDocsRelationManager::class, ['ownerRecord' => $fresh, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['data_liberacao' => '2026-11-12'])
            ->assertHasNoActionErrors()
            ->assertActionHidden(TestAction::make('create')->table());

        Livewire::test(OriginDocsRelationManager::class, ['ownerRecord' => $pending, 'pageClass' => ViewEmbarque::class])
            ->assertActionHidden(TestAction::make('create')->table())
            ->callAction(TestAction::make('edit')->table(DB::table('liberacao_embarque_origem')->where('embarque_id', $pending->getKey())->value('liberacao_embarque_id')), ['data_liberacao' => '2026-11-13'])
            ->assertHasNoActionErrors();

        $rows = DB::table('liberacao_embarque_origem')->orderBy('liberacao_embarque_id')->get();
        $this->assertSame([[1, '2026-11-13'], [1, '2026-11-12']], $rows->map(fn ($r) => [(int) $r->status, substr($r->data_liberacao, 0, 10)])->all());
        $this->assertSame([$user->getKey()], $rows->pluck('updated_by')->unique()->map(fn ($id) => (int) $id)->all());
        $this->assertCount(2, $rows);
        $this->assertSame(1, DB::table('liberacao_embarque_origem')->where('embarque_id', $pending->getKey())->count());
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'BKG'.random_int(1000, 9999)]));
    }

    private function user(): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'booking']));
    }
}
