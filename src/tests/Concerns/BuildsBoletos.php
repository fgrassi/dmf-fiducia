<?php

namespace Tests\Concerns;

use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Cliente com boleto e a API do Sicredi simulada (usar junto com LegacySchema e BuildsCharges). */
trait BuildsBoletos
{
    protected function sicredi(string $pdf = '%PDF-1.4 boleto'): void
    {
        config(['services.sicredi' => [
            'url' => 'https://sicredi.test/sb/', 'x_api_key' => 'key', 'username' => 'user', 'password' => 'secret',
            'cooperativa' => '6789', 'posto' => '03', 'beneficiary' => '12345', 'webhook_token' => 'segredo',
        ]]);
        Storage::fake('tracking_uploads');
        Http::preventStrayRequests();
        Http::fake([
            'sicredi.test/sb/auth/*' => Http::response(['access_token' => 'jwt', 'expires_in' => 300]),
            'sicredi.test/sb/cobranca/boleto/v1/boletos/pdf*' => Http::response($pdf),
            'sicredi.test/sb/cobranca/boleto/v1/boletos/*/baixa' => Http::response([], 202),
            'sicredi.test/sb/cobranca/boleto/v1/boletos*' => Http::sequence()
                ->push(['nossoNumero' => '211001234', 'linhaDigitavel' => '74891.12100 12345', 'codigoBarras' => '7489'], 201)
                ->whenEmpty(Http::response(['situacao' => 'LIQUIDADO PIX'])),
        ]);
    }

    /** IC aberta de importação marítima, R$ 1.000 + R$ 5 de taxa − R$ 10 de desconto, de um cliente com boleto IMPO. */
    protected function boletoCharge(bool $automatic = false): FinCharges
    {
        DB::table('estados')->insert(['estados_id' => 1, 'nome' => 'Rio Grande do Sul', 'uf' => 'RS']);
        DB::table('cidades')->insert(['cidades_id' => 1, 'nome' => 'Santa Cruz do Sul', 'estados_id' => 1]);
        DB::table('pessoa')->insert([
            'pessoa_id' => 20, 'nome' => 'CLIENTE <TESTE>', 'razao_social' => 'CLIENTE IMPORTADORA DE UM NOME BEM COMPRIDO LTDA', 'cnpj' => '07.052.088/0001-34',
            'cidades_id' => 1, 'endereco' => 'Rua Borges de Medeiros, 274', 'bairro' => 'Centro', 'cep' => '96810-034', 'codigo_municipio_ibge' => '4316808', 'email' => 'fin@cliente.test',
        ]);
        DB::table('fin_clients_boleto')->insert(['ref_client' => 20, 'boleto_imp' => 1, 'boleto_automatico_imp' => (int) $automatic]);

        return $this->charge(
            ['total_value' => 1000, 'tax_boleto' => 5, 'discount' => 10],
            ['impor_expor' => 'i', 'tipo_embarque' => 'm', 'cliente_id' => 20, 'pessoa_id_importador' => 20],
        );
    }
}
