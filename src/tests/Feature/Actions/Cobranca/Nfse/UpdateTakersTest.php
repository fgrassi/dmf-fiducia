<?php

namespace Tests\Feature\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Nfse\UpdateTakers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class UpdateTakersTest extends TestCase
{
    use LegacySchema;

    public function test_updates_existing_person_in_uppercase_splitting_street_and_number(): void
    {
        $stateId = DB::table('estados')->insertGetId(['nome' => 'Rio Grande do Sul', 'uf' => 'RS']);
        $cityId = DB::table('cidades')->insertGetId(['nome' => 'Santa Cruz do Sul', 'estados_id' => $stateId]);
        $personId = DB::table('pessoa')->insertGetId(['nome' => 'ACME', 'cnpj' => '11.222.333/0001-81']);

        $result = app(UpdateTakers::class)([[
            'id' => $personId, 'cnpj' => '11.222.333/0001-81', 'nome' => 'acme ltda', 'endereco' => 'rua das flores, 55', 'numero' => '',
            'bairro' => 'centro', 'cep' => '96800000', 'cidade' => 'santa cruz do sul', 'uf' => 'rs', 'ibge' => '4316808', 'pais' => '',
        ]], 7);

        $this->assertTrue($result['ok']);
        $this->assertSame('1 cadastro(s) atualizado(s) e 0 criado(s).', $result['message']);
        $person = DB::table('pessoa')->where('pessoa_id', $personId)->first();
        $this->assertSame(
            ['ACME LTDA', 'RUA DAS FLORES, 55', 'CENTRO', '96800000', '4316808', $cityId],
            [$person->nome, $person->endereco, $person->bairro, $person->cep, $person->codigo_municipio_ibge, (int) $person->cidades_id],
        );
        $this->assertNotNull($person->dteMovimentacao);
    }

    public function test_creates_person_for_unregistered_cnpj_with_author(): void
    {
        $result = app(UpdateTakers::class)([[
            'id' => '', 'cnpj' => '11222333000181', 'nome' => 'gama sa', 'endereco' => 'av brasil', 'numero' => '100',
            'bairro' => '', 'cep' => '', 'cidade' => '', 'uf' => '', 'ibge' => '', 'pais' => '',
        ]], 7);

        $person = DB::table('pessoa')->sole();
        $this->assertSame('0 cadastro(s) atualizado(s) e 1 criado(s).', $result['message']);
        $this->assertSame(['GAMA SA', '11.222.333/0001-81', 'AV BRASIL, 100', 7], [$person->nome, $person->cnpj, $person->endereco, (int) $person->created_by]);
    }

    public function test_creates_missing_city_with_official_ibge_name_in_the_existing_state(): void
    {
        Http::preventStrayRequests();
        Http::fake(['servicodados.ibge.gov.br/api/v1/localidades/municipios/4314902' => Http::response([
            'id' => 4314902, 'nome' => 'Porto Alegre', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'RS']]],
        ])]);
        $stateId = DB::table('estados')->insertGetId(['nome' => 'Rio Grande do Sul', 'uf' => 'Rio Grande do Sul']);

        app(UpdateTakers::class)([[
            'id' => '', 'cnpj' => '11222333000181', 'nome' => 'gama', 'endereco' => '', 'numero' => '', 'bairro' => '', 'cep' => '',
            'cidade' => 'poa', 'uf' => 'RS', 'ibge' => '4314902', 'pais' => '',
        ]], null);

        $city = DB::table('cidades')->sole();
        $this->assertSame(['PORTO ALEGRE', $stateId], [$city->nome, (int) $city->estados_id]);
        $this->assertSame((int) $city->cidades_id, (int) DB::table('pessoa')->value('cidades_id'));
        $this->assertSame('RS', DB::table('estados')->value('uf'));
    }

    public function test_rejects_invalid_cnpj_and_blank_name_without_writing(): void
    {
        $result = app(UpdateTakers::class)([
            ['id' => '', 'cnpj' => '11.111.111/1111-11', 'nome' => 'X'],
            ['id' => '', 'cnpj' => '11222333000181', 'nome' => '  '],
        ], null);

        $this->assertFalse($result['ok']);
        $this->assertSame(['CNPJ inválido: 11.111.111/1111-11', 'CNPJ 11.222.333/0001-81 sem nome.'], $result['errors']);
        $this->assertSame(0, DB::table('pessoa')->count());
    }

    public function test_warns_when_city_cannot_be_resolved_but_still_saves_the_person(): void
    {
        Http::preventStrayRequests();
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([])]);

        $result = app(UpdateTakers::class)([[
            'id' => '', 'cnpj' => '11222333000181', 'nome' => 'gama', 'cidade' => 'lugar nenhum', 'uf' => 'RS', 'ibge' => '',
        ]], null);

        $this->assertTrue($result['ok']);
        $this->assertSame(['CNPJ 11.222.333/0001-81: cidade LUGAR NENHUM/RS não encontrada.'], $result['errors']);
        $this->assertSame(1, DB::table('pessoa')->count());
    }
}
