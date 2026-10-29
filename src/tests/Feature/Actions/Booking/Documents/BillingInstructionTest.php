<?php

namespace Tests\Feature\Actions\Booking\Documents;

use App\Actions\Booking\Documents\BillingInstruction;
use App\Actions\Booking\Documents\BillingInstructionPdf;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BillingInstructionTest extends TestCase
{
    use LegacySchema;

    public function test_collects_the_header_route_goods_and_contacts_of_an_ocean_export(): void
    {
        $this->travelTo('2026-10-29 10:00');
        $booking = $this->oceanExport();
        $this->contact('embarque_contato_cliente', $booking, 'Bia', 'bia@x.com', '1111', 's', 1);
        $this->contact('embarque_contato', $booking, 'Caio', 'caio@x.com', '2222', 's', 1);
        $this->contact('embarque_contato_notify', $booking, 'Dani', 'dani@x.com', '3333', 'n', 1);
        $this->contact('embarque_contato_importador', $booking, 'Edu', 'edu@x.com', '4444', 's', 0);

        $data = app(BillingInstruction::class)($booking);

        $this->assertSame(
            ['OCEAN EXPORT', 'Ana DMF', '29/10/2026', 'Vendedor Lia', 'Free Hand', 'IMPORTADORA (CNPJ: 123)', 'NOTIFY SA', 'EXPORTADORA', 'REPRESENTANTE'],
            [$data['type'], $data['contact'], $data['updated_at'], $data['seller'], $data['shipment'], $data['consignee'], $data['notify'], $data['shipper'], $data['agent']],
        );
        $this->assertSame(['Santos (BR)(BRSSZ)', 'Hamburg (DE)(DEHAM)'], [$data['origin'], $data['destination']]);
        $this->assertSame("Bia (bia@x.com) - Phone: 1111\nCaio (caio@x.com) - Phone: 2222", $data['contacts']);
        $this->assertSame('2 x 40HC  |  1 x 20DC', $data['goods']);
        $this->assertSame(
            ['MAERSK COLOADER', 12, 'Agente', 'Origem / DMF', 'FOB', 'INV1', 'REF1', 'ADS0001SSZHAM/2026', 'Obs da reserva', 'Special rules for Germany', 'YES', 'NO'],
            [$data['carrier'], $data['free_time'], $data['inland'], $data['closing'], $data['incoterm'], $data['invoice'], $data['customer_ref'], $data['quotation'], $data['notes'], $data['special'], $data['deposit'], $data['insurance']],
        );
    }

    public function test_place_of_delivery_prefers_the_final_destination_and_falls_back_to_the_free_text(): void
    {
        $booking = $this->oceanExport();

        $this->assertSame('Rua A 10, 20000, Hamburg, Hamburgo, Alemanha (DE)', app(BillingInstruction::class)($booking)['delivery']);

        $booking->forceFill(['destino_final_id' => null, 'place_of_delivery' => 'Porto Seco X'])->save();

        $this->assertSame('Porto Seco X', app(BillingInstruction::class)($booking->fresh())['delivery']);
    }

    public function test_costs_show_included_selling_billing_type_and_due_to(): void
    {
        $booking = $this->oceanExport();
        $bl = DB::table('bl')->insertGetId(['embarque_id' => $booking->getKey(), 'bl' => 'HBL9']);
        DB::table('fin_expenses_values')->insert([
            ['ref_embarque' => $booking->getKey(), 'ref_expense' => 500, 'ref_bl' => $bl, 'buying_rate' => 100, 'ref_currency_br' => 2, 'selling_rate' => 150.5, 'ref_currency_sr' => 2, 'all_in' => '0', 'billing' => 'p', 'ref_due_to' => 7],
            ['ref_embarque' => $booking->getKey(), 'ref_expense' => 500, 'ref_bl' => null, 'buying_rate' => 20, 'ref_currency_br' => 2, 'selling_rate' => 0, 'ref_currency_sr' => null, 'all_in' => '1', 'billing' => 'f', 'ref_due_to' => 7],
        ]);

        $costs = app(BillingInstruction::class)($booking)['costs'];

        $this->assertSame(
            [
                ['name' => 'Doc / BL ( BL: HBL9)', 'buying' => 'USD 100,00', 'selling' => 'USD 150,50', 'billing' => 'PP', 'due_to' => 'DMF'],
                ['name' => 'Doc / BL', 'buying' => 'USD 20,00', 'selling' => 'INCLUSO', 'billing' => 'PA', 'due_to' => 'DMF'],
            ],
            $costs,
        );
    }

    public function test_brokers_keep_the_legacy_email_rule_and_list_the_clients_contacts(): void
    {
        $booking = $this->oceanExport();

        $data = app(BillingInstruction::class)($booking);

        $this->assertSame(['DMF Despachos', 'Despachante do Cliente', 'cliente@desp.com', ['Fulano (fulano@desp.com)']], [$data['dmf_broker'], $data['client_broker'], $data['client_broker_email'], $data['broker_contacts']]);

        $booking->forceFill(['despachante_dmf_id' => null])->save();

        $this->assertSame('', app(BillingInstruction::class)($booking->fresh())['client_broker_email']);
    }

    public function test_air_booking_uses_the_airline_and_weights_and_has_no_special_instructions(): void
    {
        $booking = $this->oceanExport();
        DB::table('cia_aerea')->insert(['id' => 5, 'name' => 'LATAM CARGO']);
        $booking->forceFill(['tipo_embarque' => 'a', 'ref_cia_aerea' => 5, 'impor_expor' => 'i'])->save();
        DB::table('mercadoria')->where('embarque_id', $booking->getKey())->delete();
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'nome' => 'A', 'quantidade' => '3', 'tipo_container_id' => 1, 'gross_weight' => 1234.5, 'net_weight' => 1000]);

        $data = app(BillingInstruction::class)($booking->fresh());

        $this->assertSame(['AIR IMPORT', 'LATAM CARGO', '3 x 40HC - GW: 1.234,500 - CW: 1.000,000', null], [$data['type'], $data['carrier'], $data['goods'], $data['special']]);
    }

    public function test_renders_the_pdf(): void
    {
        $pdf = app(BillingInstructionPdf::class)->render($this->oceanExport())->Output('', 'S');

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    private function oceanExport(): Embarque
    {
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']]);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert(['id' => 500, 'name' => 'Doc', 'ref_unity' => 1]);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 7, 'name' => 'DMF']);
        DB::table('tipo_container')->insert([['tipo_container_id' => 1, 'nome' => '40HC'], ['tipo_container_id' => 2, 'nome' => '20DC']]);
        DB::table('paises')->insert([['paises_id' => 1, 'nome' => 'Brasil', 'uncode' => 'BR'], ['paises_id' => 2, 'nome' => 'Alemanha', 'uncode' => 'DE']]);
        DB::table('estados')->insert(['estados_id' => 1, 'paises_id' => 2, 'nome' => 'Hamburgo']);
        DB::table('cidades')->insert(['cidades_id' => 1, 'estados_id' => 1, 'nome' => 'Hamburg']);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos', 'uncode' => 'BRSSZ', 'ref_pais' => 1], ['porto_id' => 2, 'nome' => 'Hamburg', 'uncode' => 'DEHAM', 'ref_pais' => 2]]);
        DB::table('instrucao_especial')->insert([
            ['paises_id' => 2, 'instrucao' => 'Old rules', 'updated_at' => '2026-01-01', 'updated_by' => 1],
            ['paises_id' => 2, 'instrucao' => 'Special rules for Germany', 'updated_at' => '2026-06-01', 'updated_by' => 1],
        ]);
        DB::table('destino_final')->insert(['destino_final_id' => 1, 'endereco' => 'Rua A 10', 'zip_code' => '20000', 'pessoa_id' => 1, 'cidade_id' => 1, 'estado_id' => 1, 'pais_id' => 2]);
        DB::table('armador')->insert([['armador_id' => 1, 'nome' => 'MAERSK'], ['armador_id' => 2, 'nome' => 'MAERSK COLOADER']]);
        DB::table('vendedor')->insert(['vendedor_id' => 1, 'nome' => 'Vendedor Lia']);
        DB::table('pessoa')->insert([
            ['pessoa_id' => 1, 'nome' => 'EXPORTADORA', 'cnpj' => null], ['pessoa_id' => 2, 'nome' => 'IMPORTADORA', 'cnpj' => '123'],
            ['pessoa_id' => 3, 'nome' => 'NOTIFY SA', 'cnpj' => null], ['pessoa_id' => 4, 'nome' => 'REPRESENTANTE', 'cnpj' => null],
        ]);
        $author = DB::table('usuario')->insertGetId(['nome' => 'Ana DMF da Silva', 'login' => 'ana', 'senha' => 'x', 'status' => 1]);
        DB::table('cotacao_expo')->insert(['cotacao_expo_id' => 1, 'pessoa_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'transit_time' => 30, 'armador_id' => 1, 'validade' => '2026-12-31', 'created_by' => $author, 'created_at' => '2026-03-10']);
        DB::table('despachante')->insert([['despachante_id' => 1, 'nome' => 'DMF Despachos', 'email' => 'dmf@desp.com'], ['despachante_id' => 2, 'nome' => 'Despachante do Cliente', 'email' => 'cliente@desp.com']]);
        DB::table('despachante_contato')->insert([['id' => 1, 'ref_despachante' => 2, 'name' => 'Fulano', 'email' => 'fulano@desp.com', 'status' => 1], ['id' => 2, 'ref_despachante' => 2, 'name' => 'Inativo', 'email' => 'x@desp.com', 'status' => 0]]);
        DB::table('pessoa_contato_despachante')->insert([['pessoa_id' => 1, 'despachante_id' => 2, 'despachante_contato_id' => 1], ['pessoa_id' => 1, 'despachante_id' => 2, 'despachante_contato_id' => 2]]);

        $id = DB::table('embarque')->insertGetId([
            'booking' => 'EXP1', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'de' => 'Ana DMF', 'vendedor_id' => 1, 'loading_type' => 'f',
            'pessoa_id_importador' => 2, 'pessoa_id_notify' => 3, 'pessoa_id_cliente' => 1, 'cliente_id' => 1, 'pessoa_id_representante' => 4,
            'porto_origem_id' => 1, 'porto_destino_id' => 2, 'destino_final_id' => 1, 'armador_id' => 1, 'coloader_id' => 2, 'free_time' => 12,
            'inland' => 2, 'tipo_fechamento' => 'o', 'incoterm_emb' => 'FOB', 'invoice' => 'INV1', 'customer_ref' => 'REF1', 'cotacao_expo_id' => 1,
            'embarque_obs' => 'Obs da reserva', 'garantee' => 's', 'insurance' => 'n', 'despachante_dmf_id' => 1, 'despachante_id' => 2,
        ]);
        DB::table('mercadoria')->insert([
            ['embarque_id' => $id, 'nome' => 'B', 'quantidade' => '1', 'tipo_container_id' => 2],
            ['embarque_id' => $id, 'nome' => 'A', 'quantidade' => '2', 'tipo_container_id' => 1],
        ]);

        return Embarque::find($id);
    }

    private function contact(string $table, Embarque $booking, string $name, string $email, string $phone, string $receives, int $status): void
    {
        $user = DB::table('usuario')->insertGetId(['nome' => $name, 'email' => $email, 'fone' => $phone, 'login' => $name, 'senha' => 'x', 'receber_documentos' => $receives, 'status' => $status]);
        DB::table($table)->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => $user]);
    }
}
