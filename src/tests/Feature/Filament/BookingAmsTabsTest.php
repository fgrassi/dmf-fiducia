<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\AmsAfricaRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\AmsUaeRelationManager;
use App\Models\Embarque;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingAmsTabsTest extends TestCase
{
    use LegacySchema;

    public function test_tabs_show_only_for_the_destination_country_of_each_one(): void
    {
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Durban', 'ref_pais' => 25], ['porto_id' => 2, 'nome' => 'Dubai', 'ref_pais' => 5]]);
        $africa = $this->booking(['porto_destino_id' => 1]);
        $uae = $this->booking(['porto_destino_id' => 2]);
        $cancelled = $this->booking(['porto_destino_id' => 1, 'finalizado' => 'c']);

        $this->assertSame([true, false, false], [AmsAfricaRelationManager::canViewForRecord($africa, ViewEmbarque::class), AmsAfricaRelationManager::canViewForRecord($uae, ViewEmbarque::class), AmsAfricaRelationManager::canViewForRecord($cancelled, ViewEmbarque::class)]);
        $this->assertSame([false, true], [AmsUaeRelationManager::canViewForRecord($africa, ViewEmbarque::class), AmsUaeRelationManager::canViewForRecord($uae, ViewEmbarque::class)]);
    }

    public function test_sends_the_registration_to_the_port_agent_contacts_then_confirms_with_the_agent_file(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($user = $this->user());
        DB::table('porto')->insert(['porto_id' => 1, 'nome' => 'Durban', 'ref_pais' => 25]);
        DB::table('pessoa')->insert(['pessoa_id' => 70, 'nome' => 'AGENT SA']);
        DB::table('agente_contato')->insert([
            ['ref_pessoa' => 70, 'name' => 'Joe', 'email' => 'joe@agent.com', 'porto_id' => 1, 'created_at' => '2026-01-01'],
            ['ref_pessoa' => 70, 'name' => 'Geral', 'email' => 'all@agent.com', 'porto_id' => null, 'created_at' => '2026-01-01'],
            ['ref_pessoa' => 70, 'name' => 'Outro porto', 'email' => 'other@agent.com', 'porto_id' => 9, 'created_at' => '2026-01-01'],
        ]);
        $booking = $this->booking(['porto_destino_id' => 1, 'pessoa_id_representante' => 70, 'booking' => 'BK1', 'house_bl' => 'HBL1', 'status_ams_africa' => 1]);
        DB::table('bl')->insert(['embarque_id' => $booking->getKey(), 'bl' => 'HBL1']);

        $tab = Livewire::test(AmsAfricaRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertSee('Joe (joe@agent.com)')
            ->callAction(TestAction::make('enviar')->table())
            ->assertHasNoActionErrors();

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn (SentMessage $m) => $m->getOriginalMessage())->last();
        $this->assertSame('DMF Brazil - Shipment Registration to South Africa: BK1 - HBL1', $message->getSubject());
        $this->assertEqualsCanonicalizing(['joe@agent.com', 'all@agent.com', 'draft@dmflog.com.br'], collect($message->getTo())->map->getAddress()->all());
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame(2, (int) $booking->fresh()->status_ams_africa);
        $this->assertSame(1, DB::table('ams_africa')->where('created_by', $user->getKey())->count());

        $registration = DB::table('ams_africa')->first();
        $tab->callAction(TestAction::make('confirmar')->table($registration->ams_africa_id), ['arquivo' => UploadedFile::fake()->create('../conf.php.pdf', 10, 'application/pdf')])
            ->assertHasNoActionErrors();

        $registration = DB::table('ams_africa')->first();
        $this->assertMatchesRegularExpression('/^\d{10,11}_[A-Za-z0-9]{12}\.pdf$/', $registration->arquivo);
        Storage::disk('tracking_uploads')->assertExists("ams_africa/{$registration->arquivo}");
        $this->assertNotNull($registration->data_confirmacao);
        $this->assertSame(3, (int) $booking->fresh()->status_ams_africa);
        $tab->assertActionHidden(TestAction::make('enviar')->table());
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
