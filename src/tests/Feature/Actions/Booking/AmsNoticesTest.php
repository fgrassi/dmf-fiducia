<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\Ams\AmsNotices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AmsNoticesTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Cliente SA', 'cnpj' => '123'], ['pessoa_id' => 70, 'nome' => 'AGENT', 'cnpj' => null]]);
        DB::table('porto')->insert([
            ['porto_id' => 1, 'nome' => 'Miami', 'ref_pais' => 4],
            ['porto_id' => 2, 'nome' => 'Veracruz', 'ref_pais' => 17],
            ['porto_id' => 3, 'nome' => 'Durban', 'ref_pais' => 25],
            ['porto_id' => 4, 'nome' => 'Dubai', 'ref_pais' => 5],
            ['porto_id' => 5, 'nome' => 'Hamburgo', 'ref_pais' => 1],
        ]);
    }

    public function test_us_notice_lists_bookings_without_accept_code_with_etd_in_the_next_7_days(): void
    {
        $this->booking(10, 'SEM-CODIGO', 1);
        $this->booking(11, 'COM-CODIGO', 1);
        DB::table('bl')->insert([['embarque_id' => 11, 'bl' => 'H', 'codigo_aceite' => 'ABC'], ['embarque_id' => 10, 'bl' => 'H2', 'codigo_aceite' => '']]);
        $this->booking(12, 'ETD-LONGE', 1, etd: '2026-11-20');
        $this->booking(13, 'CANCELADA', 1, status: 'c');
        $this->booking(14, 'BL-DIRETO', 1, direct: 1);
        $this->booking(15, 'AEREO', 1, type: 'a');
        $this->booking(16, 'OUTRO-PAIS', 5);

        $this->assertSame(1, app(AmsNotices::class)->missingUs());
        $this->assertSame(['[Aviso] Embarques sem AMS Enviado'], $this->sentSubjects()->all());
        $this->assertSame(['draft@dmflog.com.br'], $this->sentRecipients());
        $this->assertStringContainsString('<td>SEM-CODIGO</td><td>Cliente SA (123)</td><td>11/11/2026</td>', $this->sentHtml());
        $this->assertStringNotContainsString('COM-CODIGO', $this->sentHtml());
    }

    public function test_mexico_notice_uses_its_own_country_and_subject(): void
    {
        $this->booking(10, 'MEX', 2);
        $this->booking(11, 'EUA', 1);

        $this->assertSame(1, app(AmsNotices::class)->missingMexico());
        $this->assertSame(['[Aviso] Embarques sem AMS Enviado [México]'], $this->sentSubjects()->all());
    }

    public function test_africa_notice_lists_only_registrations_not_sent_with_etd_in_the_next_5_days(): void
    {
        $this->booking(10, 'PENDENTE', 3, status: 'n', ams: 1);
        $this->booking(11, 'JA-ENVIADO', 3, ams: 2);
        $this->booking(12, 'ETD-LONGE', 3, etd: '2026-11-16', ams: 1);

        $this->assertSame(1, app(AmsNotices::class)->pendingAfrica());
        $this->assertSame(['[Aviso] Embarques para África do Sul sem documentos enviados'], $this->sentSubjects()->all());
        $this->assertStringContainsString('<th>Agente</th>', $this->sentHtml());
        $this->assertStringContainsString('<td>AGENT</td>', $this->sentHtml());
        $this->assertStringNotContainsString('JA-ENVIADO', $this->sentHtml());
    }

    public function test_uae_notice_uses_the_uae_status_and_country(): void
    {
        $this->booking(10, 'DUBAI', 4, uaeStatus: 1);
        $this->booking(11, 'AFRICA', 3, ams: 1);

        $this->assertSame(1, app(AmsNotices::class)->pendingUae());
        $this->assertSame(['[Aviso] Embarques para os Emirados Arabes Unidos sem documentos enviados'], $this->sentSubjects()->all());
    }

    public function test_sends_nothing_when_there_is_no_booking(): void
    {
        $this->assertSame(0, app(AmsNotices::class)->missingUs());
        $this->assertSame(0, app(AmsNotices::class)->pendingAfrica());
        $this->assertCount(0, $this->sentSubjects());
    }

    public function test_reminder_goes_to_the_agent_contacts_with_the_hbl_and_the_days_since_the_registration(): void
    {
        Storage::fake('tracking_uploads');
        Storage::disk('tracking_uploads')->put('docs_emb/hbl.pdf', 'pdf');
        DB::table('cmn_files')->insert(['id' => 1, 'name' => 'hbl.pdf']);
        DB::table('emb_embarque_files')->insert(['ref_embarque' => 10, 'ref_file' => 1, 'ref_file_type' => 1]);
        DB::table('agente_contato')->insert([
            ['ref_pessoa' => 70, 'name' => 'Joe', 'email' => 'joe@agent.com', 'porto_id' => 3, 'created_at' => '2026-01-01'],
            ['ref_pessoa' => 70, 'name' => 'Outro porto', 'email' => 'other@agent.com', 'porto_id' => 9, 'created_at' => '2026-01-01'],
        ]);
        $this->booking(10, 'ENVIADO', 3, ams: 2);
        DB::table('ams_africa')->insert(['embarque_id' => 10, 'data_envio' => '2026-11-06 10:00:00', 'created_by' => 1]);
        $this->booking(11, 'CONFIRMADO', 3, ams: 2);
        DB::table('ams_africa')->insert(['embarque_id' => 11, 'data_envio' => '2026-11-01', 'data_confirmacao' => '2026-11-02', 'created_by' => 1]);
        $this->booking(12, 'NAO-ENVIADO', 3, ams: 1);

        $this->assertSame(1, app(AmsNotices::class)->reminderAfrica());

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn (SentMessage $m) => $m->getOriginalMessage())->last();
        $this->assertSame('DMF Brazil - Shipment Registration to South Africa: ENVIADO - HBL10 -  [Reminder #2]', $message->getSubject());
        $this->assertSame(['joe@agent.com'], $this->sentRecipients());
        $this->assertSame(['draft@dmflog.com.br'], $this->sentRecipients('cc'));
        $this->assertSame(['draft@dmflog.com.br'], $this->sentRecipients('replyTo'));
        $this->assertCount(1, $message->getAttachments());
        $this->assertStringContainsString('It is important and mandatory', $this->sentHtml());
        $this->assertStringContainsString('<th align="left">Booking</th><td>ENVIADO</td>', $this->sentHtml());
    }

    public function test_uae_reminder_uses_the_uae_registration(): void
    {
        $this->booking(10, 'DUBAI', 4, uaeStatus: 2);
        DB::table('ams_uae')->insert(['embarque_id' => 10, 'data_envio' => '2026-11-09 08:00:00', 'created_by' => 1]);

        $this->assertSame(1, app(AmsNotices::class)->reminderUae());
        $this->assertSame(['DMF Brazil - Shipment Registration to United Arab Emirates: DUBAI - HBL10 -  [Reminder #0]'], $this->sentSubjects()->all());
    }

    private function booking(int $id, string $booking, int $port, string $etd = '2026-11-11', string $status = 'n', string $type = 'm', int $direct = 0, int $ams = 0, int $uaeStatus = 0): void
    {
        DB::table('embarque')->insert([
            'embarque_id' => $id, 'booking' => $booking, 'house_bl' => "HBL{$id}", 'tipo_embarque' => $type, 'impor_expor' => 'e', 'finalizado' => $status,
            'saida_prevista' => $etd, 'cliente_id' => 1, 'pessoa_id_representante' => 70, 'porto_destino_id' => $port, 'bl_direto' => $direct,
            'status_ams_africa' => $ams, 'status_ams_uae' => $uaeStatus,
        ]);
    }
}
