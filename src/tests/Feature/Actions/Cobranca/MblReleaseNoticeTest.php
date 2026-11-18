<?php

namespace Tests\Feature\Actions\Cobranca;

use App\Actions\Cobranca\Notices\MblReleaseNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class MblReleaseNoticeTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert([
            ['pessoa_id' => 1, 'nome' => 'Exportador'],
            ['pessoa_id' => 2, 'nome' => 'Importador SA'],
            ['pessoa_id' => 3, 'nome' => 'Agente Hamburgo'],
            ['pessoa_id' => 4, 'nome' => 'Exportador com Boleto'],
        ]);
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Alemanha']);
        DB::table('porto')->insert([['porto_id' => 5, 'nome' => 'Hamburgo', 'ref_pais' => 1], ['porto_id' => 6, 'nome' => 'Bremen', 'ref_pais' => 1]]);
        DB::table('agente_contato')->insert([
            ['ref_pessoa' => 3, 'name' => 'Ana', 'email' => 'ana@agente.de', 'operacao' => 'e', 'porto_id' => null, 'created_at' => '2026-01-01', 'created_by' => 1],
            ['ref_pessoa' => 3, 'name' => 'Bruno', 'email' => 'bruno@agente.de', 'operacao' => 'a', 'porto_id' => 5, 'created_at' => '2026-01-01', 'created_by' => 1],
            ['ref_pessoa' => 3, 'name' => 'Carla', 'email' => 'carla@agente.de', 'operacao' => 'e', 'porto_id' => 6, 'created_at' => '2026-01-01', 'created_by' => 1],
            ['ref_pessoa' => 3, 'name' => 'Davi', 'email' => 'davi@agente.de', 'operacao' => 'i', 'porto_id' => null, 'created_at' => '2026-01-01', 'created_by' => 1],
        ]);
    }

    public function test_warns_the_agent_contacts_of_the_destination_port_and_copies_finance(): void
    {
        $this->booking(10, 'BKG10');

        $sent = app(MblReleaseNotice::class)();

        $this->assertSame(1, $sent);
        $this->assertSame(['[IMPORTANT] MBL Release Information: BKG10 - MBL: MBL10 - Cnee: Importador SA'], $this->sentSubjects()->all());
        $this->assertSame(['ana@agente.de', 'bruno@agente.de'], $this->sentRecipients());
        $this->assertSame(['financeiro@dmflog.com.br'], $this->sentRecipients('cc'));
        $this->assertSame(['financeiro@dmflog.com.br'], $this->sentRecipients('replyTo'));
        $this->assertStringContainsString('<li>MBL: MBL10</li><li>HBL: HBL10</li><li>ETD: 2026-11-12</li><li>POD: Hamburgo (Alemanha)</li>', $this->sentHtml());
    }

    public function test_skips_bookings_that_do_not_match_the_legacy_filters(): void
    {
        $this->booking(10, 'CLIENTE-BOLETO', client: 4);
        DB::table('fin_clients_boleto')->insert(['ref_client' => 4, 'prazo_exp' => 30, 'boleto_exp' => 0]);
        $this->booking(11, 'IC-PAGA', chargeStatus: 1);
        $this->booking(12, 'INCOTERM-1', incoterm: 1);
        $this->booking(13, 'CHEGA-DEPOIS', eta: '2026-11-15');
        $this->booking(14, 'CANCELADA', status: 'c');
        $this->booking(15, 'IMPORTACAO', type: 'i');

        $this->assertSame(0, app(MblReleaseNotice::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }

    private function booking(int $id, string $booking, int $client = 1, int $chargeStatus = 0, ?int $incoterm = null, string $eta = '2026-11-12', string $status = 'a', string $type = 'e'): void
    {
        DB::table('embarque')->insert([
            'embarque_id' => $id, 'booking' => $booking, 'master_bl' => "MBL{$id}", 'house_bl' => "HBL{$id}", 'tipo_embarque' => 'm', 'impor_expor' => $type,
            'finalizado' => $status, 'incoterm_id' => $incoterm, 'chegada_prevista' => $eta, 'cliente_id' => $client, 'pessoa_id_importador' => 2,
            'pessoa_id_representante' => 3, 'porto_destino_id' => 5,
        ]);
        DB::table('fin_charges')->insert(['ref_booking' => $id, 'ref_type' => 1, 'status' => $chargeStatus, 'created_at' => '2026-11-01']);
    }
}
