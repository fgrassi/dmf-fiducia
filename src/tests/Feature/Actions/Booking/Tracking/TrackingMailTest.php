<?php

namespace Tests\Feature\Actions\Booking\Tracking;

use App\Actions\Booking\Tracking\SaveTracking;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class TrackingMailTest extends TestCase
{
    use LegacySchema;

    private const DATA = ['ref_frase_transborda' => 16, 'descr' => "Loaded on vessel\nETA Port of Discharge 20/12", 'data_reg' => '2026-11-10'];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'ACME EXPORT'], ['pessoa_id' => 2, 'nome' => 'ACME IMPORT']]);
        DB::table('usuario')->insert([
            ['usuario_id' => 7, 'nome' => 'Ana', 'login' => 'ana', 'email' => 'ana@dmflog.com.br', 'status' => 1, 'receber_avisos' => 'n'],
            ['usuario_id' => 20, 'nome' => 'Cliente', 'login' => 'c', 'email' => 'cliente@acme.com', 'status' => 1, 'receber_avisos' => 's'],
            ['usuario_id' => 21, 'nome' => 'Consignee', 'login' => 'cn', 'email' => 'cnee@acme.com', 'status' => 1, 'receber_avisos' => 's'],
            ['usuario_id' => 22, 'nome' => 'Mudo', 'login' => 'm', 'email' => 'mudo@acme.com', 'status' => 1, 'receber_avisos' => 'n'],
        ]);
        DB::table('frase_transborda')->insert(['id' => 16, 'valor' => 'Booking made', 'frase' => 'x']);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos'], ['porto_id' => 2, 'nome' => 'Shanghai']]);
        DB::table('configuracoes')->insert(['identificacao' => 'rastreamento_impo', 'valor' => 'impo@dmflog.com.br']);
    }

    public function test_import_goes_in_one_message_to_every_contact_with_the_greeting_and_the_import_copies(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'free_time' => 14]);
        DB::table('embarque_contato_cliente')->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => 20]);
        DB::table('embarque_contato_importador')->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => 21]);
        DB::table('embarque_contato_cliente')->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => 22]);

        $save = app(SaveTracking::class);
        $save($booking, self::DATA, 7);

        $mails = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $mails);
        $mail = $mails->first()->getOriginalMessage();
        $this->assertSame([], $save->unsent);
        $this->assertSame('DMF Tracking: ACME EXPORT / ACME IMPORT / INV1 / REF1 / IMP1', $mail->getSubject());
        $this->assertEqualsCanonicalizing(['cliente@acme.com', 'cnee@acme.com'], $this->addresses($mail->getTo()));
        $this->assertSame(['trackingimp@dmflog.com.br'], $this->addresses($mail->getBcc()));
        $this->assertEqualsCanonicalizing(['impo@dmflog.com.br', 'tracking@dmflog.com.br', 'docimportacao@dmflog.com.br'], $this->addresses($mail->getReplyTo()));
        $html = $mail->getHtmlBody();
        $this->assertStringContainsString('OCEAN IMPORT', $html);
        $this->assertStringContainsString('14 days *', $html);
        $this->assertStringContainsString('3717-8200', $html);
        $this->assertStringContainsString('<font style="background-color: yellow">ETA Port of Discharge</font> 20/12', $html);
        $this->assertStringContainsString('10/11/2026', $html);
    }

    public function test_export_goes_in_one_message_per_contact_type_and_only_the_shipper_gets_the_greeting(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'pessoa_id_representante' => 55, 'porto_destino_id' => 2]);
        DB::table('embarque_contato')->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => 20]);
        DB::table('agente_contato')->insert([
            ['id' => 1, 'ref_pessoa' => 55, 'name' => 'Agente', 'email' => 'agente@agent.com', 'tipo' => 't', 'operacao' => 'e', 'porto_id' => 2, 'created_at' => '2026-01-01'],
            ['id' => 2, 'ref_pessoa' => 55, 'name' => 'Excluído', 'email' => 'fora@agent.com', 'tipo' => 't', 'operacao' => 'e', 'porto_id' => null, 'created_at' => '2026-01-01'],
            ['id' => 3, 'ref_pessoa' => 55, 'name' => 'Outro porto', 'email' => 'porto@agent.com', 'tipo' => 't', 'operacao' => 'e', 'porto_id' => 1, 'created_at' => '2026-01-01'],
            ['id' => 4, 'ref_pessoa' => 55, 'name' => 'Só importação', 'email' => 'impo@agent.com', 'tipo' => 't', 'operacao' => 'i', 'porto_id' => null, 'created_at' => '2026-01-01'],
        ]);
        DB::table('embarque_agente_contato_excluido')->insert(['embarque_id' => $booking->getKey(), 'agente_contato_id' => 2]);

        app(SaveTracking::class)($booking, self::DATA, 7);

        $mails = Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage());
        $this->assertCount(2, $mails);
        $shipper = $mails->first(fn (Email $m) => $this->addresses($m->getTo()) === ['cliente@acme.com']);
        $agent = $mails->first(fn (Email $m) => $this->addresses($m->getTo()) === ['agente@agent.com']);
        $this->assertStringContainsString('3717-8200', $shipper->getHtmlBody());
        $this->assertStringNotContainsString('3717-8200', $agent->getHtmlBody());
        $this->assertSame(['tracking@dmflog.com.br'], $this->addresses($agent->getBcc()));
        $this->assertStringContainsString('OCEAN EXPORT', $agent->getHtmlBody());
    }

    public function test_plain_text_clients_get_text_and_full_history_clients_get_every_message(): void
    {
        DB::table('pessoa')->where('pessoa_id', 1)->update(['histtxt' => 's']);
        DB::table('pessoa')->insert(['pessoa_id' => 3, 'nome' => 'FULL', 'histCompleto' => 'true']);
        $text = $this->booking(['cliente_id' => 1]);
        $full = $this->booking(['cliente_id' => 3]);
        foreach ([$text, $full] as $booking) {
            DB::table('embarque_contato_cliente')->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => 20]);
        }
        DB::table('transborda')->insert(['embarque_id' => $full->getKey(), 'descr' => 'Primeira mensagem', 'data_reg' => '2026-10-01']);

        app(SaveTracking::class)($text, self::DATA, 7);
        app(SaveTracking::class)($full, self::DATA, 7);

        [$plain, $history] = Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
        $this->assertNull($plain->getHtmlBody());
        $this->assertStringContainsString('Tracking System: Plain Text Version', $plain->getTextBody());
        $this->assertStringContainsString('Primeira mensagem', $history->getHtmlBody());
        $this->assertStringContainsString('Loaded on vessel', $history->getHtmlBody());
    }

    public function test_a_booking_without_valid_contacts_is_reported_as_unsent_and_a_restricted_seller_is_left_out(): void
    {
        DB::table('vendedor')->insert(['vendedor_id' => 5, 'nome' => 'Vend', 'email' => 'vend@dmflog.com.br', 'receber_avisos' => 's', 'ctr_ferias' => 0]);
        DB::table('vendedor_restricao')->insert(['vendedor_id' => 5, 'created_by' => 1, 'created_at' => '2026-01-01']);
        $booking = $this->booking(['vendedor_id' => 5, 'data_topo' => '2026-01-01']);

        $save = app(SaveTracking::class);
        $save($booking, self::DATA, 7);

        $this->assertSame([$booking->getKey()], $save->unsent);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(0, (int) DB::table('vendedor_restricao_embarque')->where('embarque_id', $booking->getKey())->value('status'));
    }

    /** @return list<string> */
    private function addresses(array $addresses): array
    {
        return array_map(fn ($address) => $address->getAddress(), $addresses);
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + [
            'booking' => 'IMP1', 'invoice' => 'INV1', 'customer_ref' => 'REF1', 'impor_expor' => 'e', 'tipo_embarque' => 'm',
            'pessoa_id_cliente' => 1, 'cliente_id' => 1, 'pessoa_id_importador' => 2, 'porto_origem_id' => 1, 'data_topo' => '2026-11-01',
        ]));
    }
}
