<?php

namespace Tests\Feature\Actions\Booking\Bls;

use App\Actions\Booking\Bls\BlDefaults;
use App\Actions\Booking\Bls\BlDocumentCheck;
use App\Actions\Booking\Bls\BlWarnings;
use App\Actions\Booking\Bls\SaveBl;
use App\Models\Bl;
use App\Models\Container;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BlTest extends TestCase
{
    use LegacySchema;

    public function test_creating_a_bl_logs_it_and_syncs_the_booking(): void
    {
        $booking = $this->booking();

        $bl = app(SaveBl::class)($booking, null, ['bl' => 'HBL-2', 'mbl_number' => 'MBL-1', 'invoice' => 'INV-1'], 7);
        app(SaveBl::class)($booking, null, ['bl' => 'HBL-1', 'mbl_number' => 'MBL-1', 'invoice' => 'INV-2'], 7);

        $this->assertSame([Bl::OPEN, 7, Bl::DEFAULT_PRINTS], [$bl->status, (int) $bl->usuario_id, (int) $bl->fresh()->numImpressao]);
        $booking->refresh();
        $this->assertSame([2, 'HBL-1 / HBL-2', 'MBL-1', 'INV-2/INV-1'], [(int) $booking->qtd_bl, $booking->house_bl, $booking->master_bl, $booking->invoice]);
        $log = DB::table('rastreamento')->orderBy('id')->first();
        $this->assertSame(['Cadastro: HBL', 'BL Cadastrado no Sistema', 'i', $booking->getKey()], [$log->assunto, $log->historico, $log->evento, (int) $log->ref_embarque]);
    }

    public function test_updating_a_bl_logs_the_new_data_with_its_containers_escaped(): void
    {
        $booking = $this->booking(['booking' => 'EXP-9']);
        $bl = app(SaveBl::class)($booking, null, ['bl' => 'HBL-1'], 7);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => "40' HC"]);
        DB::table('container')->insert(['bl_id' => $bl->getKey(), 'tipo_container_id' => 1, 'numero' => 'ABCD1234567']);

        app(SaveBl::class)($booking, $bl, ['shipper' => '<b>ACME</b>'], 7);

        $log = DB::table('rastreamento')->where('evento', 'u')->first();
        $this->assertSame('Atualizado: HBL', $log->assunto);
        $this->assertStringContainsString('&lt;b&gt;ACME&lt;/b&gt;', $log->historico);
        $this->assertStringContainsString('EXP-9', $log->historico);
        $this->assertStringContainsString('ABCD1234567', $log->historico);
    }

    public function test_deleting_a_bl_logs_it_and_resyncs_the_booking(): void
    {
        $booking = $this->booking();
        $keep = app(SaveBl::class)($booking, null, ['bl' => 'KEEP', 'invoice' => 'INV-1'], 7);
        $drop = app(SaveBl::class)($booking, null, ['bl' => 'DROP', 'invoice' => 'INV-2'], 7);

        app(SaveBl::class)->delete($booking, $drop, 7);

        $this->assertSame([$keep->getKey()], DB::table('bl')->pluck('bl_id')->all());
        $booking->refresh();
        $this->assertSame([1, 'KEEP', 'INV-1'], [(int) $booking->qtd_bl, $booking->house_bl, $booking->invoice]);
        $this->assertSame('Apagado: BL', DB::table('rastreamento')->where('evento', 'd')->value('assunto'));
    }

    public function test_the_importer_rnc_is_completed_only_when_missing(): void
    {
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Sem RNC', 'rnc' => 'N/A'], ['pessoa_id' => 2, 'nome' => 'Com RNC', 'rnc' => 'JA-TEM']]);

        app(SaveBl::class)($this->booking(['pessoa_id_importador' => 1]), null, ['bl' => 'A', 'rnc' => 'NOVO'], 7);
        app(SaveBl::class)($this->booking(['pessoa_id_importador' => 2]), null, ['bl' => 'B', 'rnc' => 'NOVO'], 7);

        $this->assertSame('NOVO', DB::table('pessoa')->where('pessoa_id', 1)->value('rnc'));
        $this->assertSame('JA-TEM', DB::table('pessoa')->where('pessoa_id', 2)->value('rnc'));
    }

    public function test_defaults_come_from_the_booking(): void
    {
        DB::table('navio')->insert(['navio_id' => 1, 'nome' => 'MSC AURORA']);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Rio Grande'], ['porto_id' => 2, 'nome' => 'Rotterdam']]);
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Holanda']);
        DB::table('estados')->insert(['estados_id' => 1, 'paises_id' => 1]);
        DB::table('cidades')->insert(['cidades_id' => 1, 'estados_id' => 1, 'nome' => 'Amsterdam']);
        DB::table('pessoa')->insert(['pessoa_id' => 55, 'nome' => 'Agente', 'razao_social' => 'Agent BV', 'endereco' => 'Street 1', 'fone1' => '123', 'cidades_id' => 1]);

        $defaults = app(BlDefaults::class)($this->booking(['navio_id' => 1, 'vg' => '012E', 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'place_of_delivery' => 'Warehouse X', 'pessoa_id_representante' => 55]));

        $this->assertSame(['MSC AURORA - 012E', 'Rio Grande', 'Rotterdam', 'Warehouse X'], [$defaults['vessel'], $defaults['port_lading'], $defaults['port_disc'], $defaults['place_delivery']]);
        $this->assertSame("Agent BV\nStreet 1\nPH: 123\nAmsterdam, Holanda\n", $defaults['representation']);
    }

    public function test_defaults_use_placeholders_when_the_booking_has_nothing(): void
    {
        $defaults = app(BlDefaults::class)($this->booking());

        $this->assertSame(['XXXXXXX', 'XXXXXXX', 'XXXXXXXXXXXXXXXX', ''], [$defaults['port_lading'], $defaults['port_disc'], $defaults['place_delivery'], $defaults['representation']]);
    }

    public function test_export_containers_open_the_detention_and_removed_ones_leave_the_bl(): void
    {
        $booking = $this->booking(['pessoa_id_cliente' => 5]);
        $row = ['tipo_container_id' => 1, 'numero' => 'AAAA1111111', 'tara' => '3', 'seals_n1' => 'S1', 'qty_pkgs' => '1', 'gross_weight' => '10', 'net_weight' => '9', 'volume' => '1'];

        $bl = app(SaveBl::class)($booking, null, ['bl' => 'HBL-1', 'containers' => [$row, [...$row, 'numero' => 'BBBB2222222']]], 7);
        app(SaveBl::class)($booking, $bl, ['containers' => [[...$row, 'container_id' => Container::where('numero', 'AAAA1111111')->value('container_id')]]], 7);

        $this->assertSame(['AAAA1111111'], DB::table('container')->pluck('numero')->all());
        $this->assertSame(['AAAA1111111', 'BBBB2222222'], DB::table('fin_detention')->orderBy('id')->pluck('container')->all());
    }

    public function test_import_containers_open_the_demurrage_with_the_free_time_and_drop_the_orphans(): void
    {
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => "40' HC"]);
        $booking = $this->booking(['impor_expor' => 'i', 'free_time' => 7]);
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => 1, 'free_time' => '14']);
        $row = ['tipo_container_id' => 1, 'numero' => 'AAAA1111111', 'tara' => '3', 'seals_n1' => 'S1', 'qty_pkgs' => '1', 'gross_weight' => '10', 'net_weight' => '9', 'volume' => '1'];
        DB::table('fin_demurrage')->insert(['container' => 'OLD0000000', 'ref_embarque' => $booking->getKey(), 'ref_tipo_container' => 1, 'created_at' => '2026-01-01', 'created_by' => 1]);

        app(SaveBl::class)($booking, null, ['bl' => 'HBL-1', 'containers' => [$row]], 7);

        $this->assertSame(['AAAA1111111', '14'], [DB::table('fin_demurrage')->value('container'), (string) DB::table('fin_demurrage')->value('free_time')]);
        $this->assertSame(1, DB::table('fin_demurrage')->count());
        $this->assertStringContainsString('OLD0000000, tipo 40\' HC', DB::table('rastreamento')->where('assunto', 'Equipamento removido da aba Demurrage')->value('historico'));
    }

    public function test_a_reefer_export_container_needs_its_temperature(): void
    {
        $booking = $this->booking();
        $row = ['tipo_container_id' => 8, 'numero' => 'RRRR1111111', 'tara' => '3', 'seals_n1' => 'S1', 'qty_pkgs' => '1', 'gross_weight' => '10', 'net_weight' => '9', 'volume' => '1'];

        $this->expectExceptionMessage('É preciso informar a Temperatura para container REEFER.');

        app(SaveBl::class)($booking, null, ['bl' => 'HBL-1', 'containers' => [$row]], 7);
    }

    public function test_diverging_reefer_temperatures_alert_expo_and_draft_and_warn_the_user(): void
    {
        $booking = $this->booking();
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => 8, 'temperatura' => -20]);
        $row = ['tipo_container_id' => 8, 'numero' => 'RRRR1111111', 'tara' => '3', 'seals_n1' => 'S1', 'qty_pkgs' => '1', 'gross_weight' => '10', 'net_weight' => '9', 'volume' => '1', 'temperatura' => -18];

        $bl = app(SaveBl::class)($booking, null, ['bl' => 'HBL-1', 'containers' => [$row]], 7);

        $this->assertSame(['[Aviso] Temperatura REEFER divergente - '.$booking->booking], $this->sentSubjects()->all());
        $this->assertSame(['A temperatura informada para este container REEFER diverge da temperatura informada na reserva.'], app(BlWarnings::class)($booking, $bl));
    }

    public function test_lcl_volume_must_match_the_booking_or_exportacao_is_told(): void
    {
        $booking = $this->booking();
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => 20, 'cubature' => 10]);
        $row = ['tipo_container_id' => 20, 'numero' => 'LCL1', 'tara' => '0', 'seals_n1' => 'S1', 'qty_pkgs' => '1', 'gross_weight' => '10', 'net_weight' => '9', 'volume' => '9,5'];

        $bl = app(SaveBl::class)($booking, null, ['bl' => 'HBL-1', 'containers' => [$row]], 7);
        $this->assertTrue(app(BlWarnings::class)->lclVolumeDiverges($booking, $bl));
        $this->assertCount(1, $this->sentSubjects());

        app(SaveBl::class)($booking, $bl, ['containers' => [[...$row, 'container_id' => Container::value('container_id'), 'volume' => '10']]], 7);
        $this->assertFalse(app(BlWarnings::class)->lclVolumeDiverges($booking, $bl));
        $this->assertCount(1, $this->sentSubjects());
    }

    public function test_a_freight_payable_at_that_differs_from_the_booking_alerts_and_is_logged(): void
    {
        $booking = $this->booking(['tipo_frete' => 'p']);

        app(SaveBl::class)($booking, null, ['bl' => 'OK', 'freight_at' => 'ORIGIN'], 7);
        $this->assertCount(0, $this->sentSubjects());

        app(SaveBl::class)($booking, null, ['bl' => 'DIVERGE', 'freight_at' => 'DESTINATION'], 7);
        $this->assertSame(['[ALERTA] Inconsistência de Local de Pagamento de Frete: '.$booking->booking], $this->sentSubjects()->all());
        $this->assertSame(1, DB::table('rastreamento')->where('assunto', 'Inconsistência de Local de Pagamento de Frete')->count());
    }

    public function test_save_data_keeps_the_party_text_for_the_next_bls(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'pessoa_id_cliente' => 5, 'pessoa_id_importador' => 6]);

        app(SaveBl::class)($booking, null, ['bl' => 'A', 'shipper' => 'SHIPPER TEXT', 'consignee' => 'CNEE TEXT', 'addShipper' => true, 'addCnee' => true], 7);

        $this->assertSame(['SHIPPER TEXT' => 5, 'CNEE TEXT' => 6], DB::table('cmn_dados_bl')->pluck('ref_pessoa', 'dados')->all());
    }

    public function test_the_document_check_tells_missing_from_invalid_cnpj(): void
    {
        $check = app(BlDocumentCheck::class);

        $this->assertSame('ok', $check("ACME\nCNPJ: 11.222.333/0001-81")['status']);
        $this->assertSame('CNPJ inválido: 11.222.333/0001-82', $check("ACME\nCNPJ: 11.222.333/0001-82")['message']);
        $this->assertSame('Não há CNPJ informado.', $check('ACME LTDA')['message']);
    }

    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'EXP'.random_int(1000, 9999), 'impor_expor' => 'e', 'tipo_embarque' => 'm']));
    }
}
