<?php

namespace Tests\Feature\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Nfse\IssueNfse;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class IssueNfsePayloadTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    public function test_sends_taker_address_and_notes_without_characters_the_sefin_rejects(): void
    {
        DB::table('config_nfse')->insert(['config_nfse_id' => 1, 'emissor_cnpj' => '11222333000181', 'emissor_codigo_municipio' => '4314902']);
        $charge = $this->charge(['total_value' => 100], ['booking' => 'BKG – 10', 'house_bl' => "HBL\r\n1"]);
        $operationId = DB::table('config_nfse_operacao')->insertGetId(['codigo_operacao' => 'EXPO', 'impor_expor' => 'e', 'codigo_tributacao_nacional' => '100101']);
        $operation = IssueNfse::operationById($operationId);
        $taker = [
            'tipo' => 'pj', 'cpf_cnpj' => '11444777000161', 'nome' => 'ACME',
            'endereco' => ['cep' => '90010000', 'logradouro' => 'Rua A – Sala “3”', 'numero' => '12', 'bairro' => 'Centro', 'codigo_municipio' => '4314902', 'uf' => 'RS'],
        ];

        $payload = app(IssueNfse::class)->payload($charge, $operation, $taker, 'Serviço', 'BKG10-1', null, null);

        $this->assertSame('Rua A - Sala "3"', $payload['tomador']['endereco']['logradouro']);
        $this->assertSame('Reserva: BKG - 10 ('.$charge->id.') | BLs (numeros): HBL 1', $payload['informacoes_complementares']);
    }
}
