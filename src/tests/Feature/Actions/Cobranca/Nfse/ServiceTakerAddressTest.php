<?php

namespace Tests\Feature\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Nfse\ServiceTaker;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ServiceTakerAddressTest extends TestCase
{
    use LegacySchema;

    public function test_truncates_typed_district_and_street_instead_of_rejecting_them(): void
    {
        $taker = app(ServiceTaker::class);
        $form = [
            'tipo' => 'pj', 'cpf_cnpj' => '11.222.333/0001-81', 'nome' => 'ACME',
            'endereco_cep' => '90010-000', 'endereco_logradouro' => 'Rua A – '.str_repeat('x', 300), 'endereco_numero' => '12',
            'endereco_bairro' => str_repeat('b', 70), 'endereco_codigo_municipio' => '4314902', 'endereco_uf' => 'rs',
        ];

        $completed = $taker->completeAddress($taker->extract($form));
        $taker->validate($completed);

        $this->assertSame(60, strlen($completed['endereco']['bairro']));
        $this->assertSame(255, strlen($completed['endereco']['logradouro']));
        $this->assertStringStartsWith('Rua A - ', $completed['endereco']['logradouro']);
    }

    public function test_completes_empty_fields_from_the_person_record_sanitized(): void
    {
        $stateId = DB::table('estados')->insertGetId(['nome' => 'Rio Grande do Sul', 'uf' => 'RS']);
        $cityId = DB::table('cidades')->insertGetId(['nome' => 'PORTO ALEGRE', 'estados_id' => $stateId]);
        DB::table('pessoa')->insert([
            'nome' => 'ACME', 'cnpj' => '11.222.333/0001-81', 'endereco' => 'Av. Brasil – Sala 3, 120', 'bairro' => 'Centro', 'cep' => '90010000',
            'codigo_municipio_ibge' => '4314902', 'cidades_id' => $cityId,
        ]);
        $taker = app(ServiceTaker::class);

        $completed = $taker->completeAddress(['tipo' => 'pj', 'cpf_cnpj' => '11222333000181', 'nome' => 'ACME', 'endereco' => ['cep' => '', 'logradouro' => '']]);

        $this->assertSame(
            ['cep' => '90010000', 'logradouro' => 'Av. Brasil - Sala 3', 'numero' => '120', 'bairro' => 'Centro', 'codigo_municipio' => '4314902', 'uf' => 'RS'],
            $completed['endereco'],
        );
    }
}
