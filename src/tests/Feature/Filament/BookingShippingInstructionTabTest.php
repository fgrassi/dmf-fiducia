<?php

namespace Tests\Feature\Filament;

use App\Actions\Booking\ShippingInstruction\ShippingInstructionPdf;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\ShippingInstructionRelationManager;
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

class BookingShippingInstructionTabTest extends TestCase
{
    use LegacySchema;

    public function test_tab_shows_only_for_open_imports(): void
    {
        $import = $this->booking();
        $export = $this->booking(['impor_expor' => 'e']);
        $suspended = $this->booking(['finalizado' => 'p']);

        $this->assertSame([true, false, false], [
            ShippingInstructionRelationManager::canViewForRecord($import, ViewEmbarque::class),
            ShippingInstructionRelationManager::canViewForRecord($export, ViewEmbarque::class),
            ShippingInstructionRelationManager::canViewForRecord($suspended, ViewEmbarque::class),
        ]);
    }

    public function test_sends_the_ocean_instruction_to_the_authorized_origin_contacts_with_pdf_and_files_and_logs_it(): void
    {
        Storage::fake('tracking_uploads');
        Storage::disk('tracking_uploads')->put('docs_emb/emb_BK1_si.pdf', 'file');
        $user = $this->user();
        $this->actingAs($user);
        $booking = $this->booking();
        $this->attachInstructionFile($booking, 'emb_BK1_si.pdf');

        Livewire::test(ShippingInstructionRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertSee('Joe (joe@agent.com)')
            ->callAction(TestAction::make('enviar')->table(), ['notes' => 'Call the shipper first'])
            ->assertHasNoActionErrors();

        $message = $this->lastMessage();
        $this->assertSame('DMF - S.I. BK1 (Hamburgo) - SHIPPER SA / CNEE LTDA - INV9', $message->getSubject());
        $this->assertEqualsCanonicalizing(['joe@agent.com', 'both@agent.com'], collect($message->getTo())->map->getAddress()->all());
        $this->assertEqualsCanonicalizing(['diego@dmflog.com.br', 'docimp@dmflog.com.br', 'docimp2@dmflog.com.br', 'importacao@dmflog.com.br'], collect($message->getCc())->map->getAddress()->all());
        $this->assertSame('ana@dmflog.com.br', $message->getReplyTo()[0]->getAddress());
        $this->assertEqualsCanonicalizing(['si_BK1.pdf', 'emb_BK1_si.pdf'], collect($message->getAttachments())->map(fn ($a) => $a->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename'))->all());
        $this->assertStringContainsString('Dear Joe, Both', $message->getHtmlBody());
        $this->assertStringContainsString('Call the shipper first', $message->getHtmlBody());
        $this->assertStringContainsString('Ana', $message->getHtmlBody());

        $tracking = DB::table('rastreamento')->where('ref_embarque', $booking->getKey())->first();
        $this->assertSame(['INSTRUÇÃO ENVIADA AO AGENTE', 't', $user->getKey()], [$tracking->assunto, $tracking->evento, (int) $tracking->created_by]);
    }

    public function test_does_not_send_without_an_authorized_contact_for_the_port_operation_and_modal(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking(['porto_origem_id' => 99]);

        Livewire::test(ShippingInstructionRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('enviar')->table(), ['notes' => ''])
            ->assertNotified('Nenhum contato autorizado do agente encontrado para este porto/modal/operação.');

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(0, DB::table('rastreamento')->count());
    }

    public function test_air_instruction_has_its_own_subject_and_no_ocean_copies(): void
    {
        $this->actingAs($this->user());
        DB::table('agente_contato')->insert(['ref_pessoa' => 70, 'name' => 'Air', 'email' => 'air@agent.com', 'porto_id' => 1, 'operacao' => 'i', 'modal' => 'a', 'autorizado' => 1, 'created_at' => '2026-01-01']);
        $booking = $this->booking(['tipo_embarque' => 'a']);

        Livewire::test(ShippingInstructionRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('enviar')->table(), ['notes' => '']);

        $message = $this->lastMessage();
        $this->assertSame('DMF LOGISTICS DO BRASIL - AIR SHIPPING INSTRUCTIONS BK1(Hamburgo) SHIPPER: SHIPPER SA //CNEE: CNEE LTDA - INV9', $message->getSubject());
        $this->assertEqualsCanonicalizing(['air@agent.com', 'both@agent.com'], collect($message->getTo())->map->getAddress()->all());
        $this->assertSame(['importacao@dmflog.com.br'], collect($message->getCc())->map->getAddress()->all());
        $this->assertStringContainsString('Please find attached our AIR Shipping Instructions.', $message->getHtmlBody());
    }

    public function test_warns_the_import_team_when_the_client_requires_insurance_and_the_booking_has_none(): void
    {
        $this->actingAs($this->user());
        $booking = $this->booking(['ctr_seguro' => 1, 'cliente_id' => 5]);
        DB::table('pessoa')->where('pessoa_id', 5)->update(['seguro_obrigatorio' => 's']);

        Livewire::test(ShippingInstructionRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('enviar')->table(), ['notes' => '']);

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn (SentMessage $m) => $m->getOriginalMessage());
        $this->assertCount(2, $messages);
        $this->assertSame('[Aviso] Reserva Sem Seguro: BK1', $messages->last()->getSubject());
        $this->assertSame(['importacao@dmflog.com.br'], collect($messages->last()->getCc())->map->getAddress()->all());
    }

    public function test_adds_a_file_as_instruction_file_and_deletes_it_keeping_the_stored_file(): void
    {
        Storage::fake('tracking_uploads');
        $user = $this->user();
        $this->actingAs($user);
        DB::table('emb_embarque_files_types')->insert(['id' => 7, 'name' => 'Packing']);
        $booking = $this->booking(['booking' => 'BK 1']);

        $tab = Livewire::test(ShippingInstructionRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('adicionar')->table(), ['type' => 7, 'file' => UploadedFile::fake()->create('my packing!.pdf', 10, 'application/pdf')])
            ->assertHasNoActionErrors();

        Storage::disk('tracking_uploads')->assertExists('docs_emb/emb_BK_1_my_packing.pdf');
        $row = DB::table('emb_embarque_files')->first();
        $this->assertSame([$booking->getKey(), 7, 1], [(int) $row->ref_embarque, (int) $row->ref_file_type, (int) $row->ctr_instr]);
        $file = DB::table('cmn_files')->first();
        $this->assertSame(['emb_BK_1_my_packing.pdf', sha1((string) $file->id), $user->getKey()], [$file->name, $file->uid, (int) $file->created_by]);
        $tab->assertSee('emb_BK_1_my_packing.pdf')->assertSee('Packing');

        $tab->callAction(TestAction::make('delete')->table($row->id));

        $this->assertSame(0, DB::table('emb_embarque_files')->count());
        Storage::disk('tracking_uploads')->assertExists('docs_emb/emb_BK_1_my_packing.pdf');
    }

    public function test_renders_the_ocean_and_air_pdfs(): void
    {
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => '40HC']);
        DB::table('mercadoria')->insert(['embarque_id' => 1, 'nome' => 'TIRES', 'quantidade' => '2', 'tipo_container_id' => 1]);

        foreach (['m', 'a'] as $type) {
            $pdf = app(ShippingInstructionPdf::class)->render($this->booking(['tipo_embarque' => $type]));

            $this->assertStringStartsWith('%PDF', $pdf->Output('si.pdf', 'S'));
        }
    }

    private function lastMessage()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn (SentMessage $m) => $m->getOriginalMessage())->first();
    }

    private function attachInstructionFile(Embarque $booking, string $name): void
    {
        $fileId = DB::table('cmn_files')->insertGetId(['name' => $name]);
        DB::table('emb_embarque_files')->insert([
            ['ref_embarque' => $booking->getKey(), 'ref_file' => $fileId, 'ref_file_type' => 1, 'ctr_instr' => 1],
            ['ref_embarque' => $booking->getKey(), 'ref_file' => $fileId, 'ref_file_type' => 1, 'ctr_instr' => null],
        ]);
    }

    private function booking(array $attributes = []): Embarque
    {
        DB::table('porto')->insertOrIgnore(['porto_id' => 1, 'nome' => 'Hamburgo']);
        DB::table('pessoa')->insertOrIgnore([['pessoa_id' => 5, 'nome' => 'CLIENTE'], ['pessoa_id' => 6, 'nome' => 'SHIPPER SA'], ['pessoa_id' => 7, 'nome' => 'CNEE LTDA'], ['pessoa_id' => 70, 'nome' => 'AGENT']]);
        DB::table('agente_contato')->insertOrIgnore([
            ['id' => 1, 'ref_pessoa' => 70, 'name' => 'Joe', 'email' => 'joe@agent.com', 'porto_id' => 1, 'operacao' => 'i', 'modal' => 'm', 'autorizado' => 1, 'created_at' => '2026-01-01'],
            ['id' => 2, 'ref_pessoa' => 70, 'name' => 'Both', 'email' => 'both@agent.com', 'porto_id' => 1, 'operacao' => 'a', 'modal' => 'b', 'autorizado' => 1, 'created_at' => '2026-01-01'],
            ['id' => 3, 'ref_pessoa' => 70, 'name' => 'Pending', 'email' => 'pending@agent.com', 'porto_id' => 1, 'operacao' => 'i', 'modal' => 'm', 'autorizado' => 0, 'created_at' => '2026-01-01'],
            ['id' => 4, 'ref_pessoa' => 70, 'name' => 'Export', 'email' => 'export@agent.com', 'porto_id' => 1, 'operacao' => 'e', 'modal' => 'm', 'autorizado' => 1, 'created_at' => '2026-01-01'],
            ['id' => 5, 'ref_pessoa' => 70, 'name' => 'Other port', 'email' => 'other@agent.com', 'porto_id' => 9, 'operacao' => 'i', 'modal' => 'm', 'autorizado' => 1, 'created_at' => '2026-01-01'],
        ]);

        return Embarque::find(DB::table('embarque')->insertGetId($attributes + [
            'booking' => 'BK1', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'finalizado' => 'n', 'invoice' => 'INV9', 'porto_origem_id' => 1,
            'pessoa_id_representante' => 70, 'pessoa_id_cliente' => 6, 'pessoa_id_importador' => 7,
        ]));
    }

    private function user(): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'booking', 'email' => 'ana@dmflog.com.br']));
    }
}
