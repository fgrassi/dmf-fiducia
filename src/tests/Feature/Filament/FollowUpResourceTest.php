<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ExportFollowUps\Pages\ManageExportFollowUps;
use App\Filament\Resources\ImportFollowUps\Pages\ManageImportFollowUps;
use App\Models\CmnClientFup;
use App\Models\User;
use App\Models\VisitaCliente;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class FollowUpResourceTest extends TestCase
{
    use LegacySchema;

    public function test_each_area_lists_its_own_follow_ups_and_only_managers_see_other_users(): void
    {
        $importer = $this->user('import');
        $exporter = $this->user('export');
        $seller = $this->user('import');
        DB::table('cmn_vendedor_usuario')->insert(['ref_usuario' => $seller->getKey(), 'ref_vendedor' => 1]);
        $client = $this->client();

        $imp = $this->fup($importer, $client, 'i');
        $exp = $this->fup($exporter, $client, 'e');
        $sellerImp = $this->fup($seller, $client, 'i');
        $finishedQuotation = $this->fup($seller, $client, 'e', 'Cotação EXP-1 finalizada: cliente desistiu');

        $this->actingAs($this->user('manager'));
        Livewire::test(ManageImportFollowUps::class)
            ->assertCanSeeTableRecords([$imp])
            ->assertCanNotSeeTableRecords([$exp, $sellerImp, $finishedQuotation]);
        Livewire::test(ManageExportFollowUps::class)
            ->assertCanSeeTableRecords([$exp, $finishedQuotation])
            ->assertCanNotSeeTableRecords([$imp, $sellerImp]);

        $this->actingAs($importer);
        Livewire::test(ManageImportFollowUps::class)->assertCanSeeTableRecords([$imp]);
        $this->actingAs($this->user('import'));
        Livewire::test(ManageImportFollowUps::class)->assertCanNotSeeTableRecords([$imp]);
    }

    public function test_creating_records_the_rating_the_city_and_the_next_contact_closing_the_previous_ones(): void
    {
        $this->actingAs($user = $this->user('import'));
        $client = $this->client();
        $city = DB::table('cidades')->insertGetId(['nome' => 'Santos']);
        $oldRating = DB::table('avaliacao_cliente')->insertGetId(['pessoa_id' => $client, 'tipo' => 'i', 'status' => 0, 'volume_embarques' => 2, 'abertura' => 1]);
        $oldVisit = VisitaCliente::create(['usuario_id' => $user->getKey(), 'pessoa_id' => $client, 'status' => 0]);

        Livewire::test(ManageImportFollowUps::class)
            ->mountAction('create')
            ->fillForm(['ref_pessoa' => $client])
            ->assertSchemaStateSet(['volume_embarques' => 2, 'abertura' => 1])
            ->fillForm([
                'ref_pessoa' => $client,
                'cidades_id' => $city,
                'data_ocorrencia' => '2026-11-20',
                'tipo' => 'i',
                'descr' => 'Visita ao cliente',
                'volume_embarques' => 3,
                'abertura' => 4,
                'data_proximo_contato' => '2026-12-20',
                'obs' => 'Ligar antes',
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $fup = CmnClientFup::sole();
        $this->assertSame([0, $user->getKey(), 3, 4], [(int) $fup->status, $fup->ref_user, (int) $fup->volume_embarques, (int) $fup->abertura]);
        $this->assertSame($city, (int) DB::table('pessoa')->where('pessoa_id', $client)->value('cidades_id'));
        $this->assertSame(1, (int) DB::table('avaliacao_cliente')->where('avaliacao_cliente_id', $oldRating)->value('status'));
        $this->assertSame(1, DB::table('avaliacao_cliente')->where('pessoa_id', $client)->where('status', 0)->where('volume_embarques', 3)->count());
        $this->assertSame(1, (int) $oldVisit->fresh()->status);
        $this->assertSame('Ligar antes', VisitaCliente::where('status', 0)->sole()->obs);
    }

    public function test_editing_a_follow_up_already_sent_warns_the_managers(): void
    {
        $this->actingAs($user = $this->user('import'));
        $client = $this->client();
        $fup = $this->fup($user, $client, 'i');
        $fup->update(['status' => 1, 'descr' => 'Relato antigo']);

        Livewire::test(ManageImportFollowUps::class)
            ->callAction(
                TestAction::make('edit')->table($fup),
                ['data_ocorrencia' => '2026-11-21', 'descr' => 'Relato novo'],
            )
            ->assertHasNoFormErrors();

        $this->assertSame('Relato novo', $fup->fresh()->descr);
        $this->assertSame(['FUP alterado'], $this->sentSubjects()->all());
        $this->assertStringContainsString('Relato antigo', $this->sentHtml());
        $this->assertStringContainsString('Relato novo', $this->sentHtml());
    }

    private function client(): int
    {
        return DB::table('pessoa')->insertGetId(['nome' => 'Cliente SA']);
    }

    private function fup(User $user, int $client, string $type, string $descr = 'Relato'): CmnClientFup
    {
        return CmnClientFup::create(['ref_pessoa' => $client, 'ref_user' => $user->getKey(), 'tipo' => $type, 'descr' => $descr, 'created_at' => '2026-11-01', 'data_ocorrencia' => '2026-11-01', 'status' => 0]);
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
