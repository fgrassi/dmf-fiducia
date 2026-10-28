<?php

namespace Tests\Feature\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Nfse\PendingTakers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PendingTakersTest extends TestCase
{
    use LegacySchema;

    private const ACME = '11222333000181';

    private const BETA = '11444777000161';

    public function test_lists_taker_with_incomplete_address_and_skips_complete_one(): void
    {
        $this->booking('e', 'saida_prevista', '2026-11-03', shipper: "ACME LTDA\nCNPJ: 11.222.333/0001-81");
        $this->booking('e', 'saida_prevista', '2026-11-03', shipper: "BETA LTDA\nCNPJ: 11.444.777/0001-61");
        $this->person('ACME LTDA', '11.222.333/0001-81', endereco: 'RUA A', cep: '', bairro: '');
        $this->person('BETA LTDA', '11.444.777/0001-61', endereco: 'RUA B, 10', cep: '90000000', bairro: 'CENTRO', ibge: '4314902', complete: true);

        $rows = app(PendingTakers::class)('2026-11-01', '2026-11-05');

        $this->assertCount(1, $rows);
        $this->assertSame('ACME LTDA', $rows[0]['nome']);
        $this->assertContains('CEP do tomador deve ter 8 dígitos.', $rows[0]['pendencias']);
        $this->assertContains('Número do tomador é obrigatório. Use S/N quando não houver número.', $rows[0]['pendencias']);
        $this->assertContains('Cidade do tomador não informada.', $rows[0]['pendencias']);
    }

    public function test_reports_company_without_person_record(): void
    {
        $this->booking('i', 'chegada_prevista', '2026-11-03', consignee: "GAMA SA\nCNPJ 11.222.333/0001-81");

        $rows = app(PendingTakers::class)('2026-11-01', '2026-11-05');

        $this->assertSame(['Empresa sem cadastro de pessoa.'], $rows[0]['pendencias']);
        $this->assertSame('11.222.333/0001-81', $rows[0]['cnpj']);
        $this->assertSame('', $rows[0]['id']);
    }

    public function test_reads_shipper_on_export_and_consignee_on_import_only(): void
    {
        $this->booking('e', 'saida_prevista', '2026-11-03', shipper: 'SEM DOC', consignee: 'CNPJ 11.222.333/0001-81');
        $this->booking('i', 'chegada_prevista', '2026-11-03', shipper: 'CNPJ 11.444.777/0001-61', consignee: 'SEM DOC');

        $rows = app(PendingTakers::class)('2026-11-01', '2026-11-05');

        $this->assertSame([], $rows);
    }

    public function test_ignores_cancelled_bookings_and_dates_outside_the_period(): void
    {
        $this->booking('e', 'saida_prevista', '2026-11-03', shipper: 'CNPJ 11.222.333/0001-81', finalizado: 'c');
        $this->booking('e', 'saida_prevista', '2026-12-20', shipper: 'CNPJ 11.444.777/0001-61');

        $rows = app(PendingTakers::class)('2026-11-01', '2026-11-05');

        $this->assertSame([], $rows);
    }

    public function test_suggests_missing_fields_from_opencnpj_only_when_asked(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.opencnpj.org/*' => Http::response([
            'cnpj' => self::ACME, 'razao_social' => 'ACME LTDA', 'tipo_logradouro' => 'RUA', 'logradouro' => 'DAS FLORES', 'numero' => '55',
            'bairro' => 'CENTRO', 'cep' => '90010000', 'uf' => 'RS', 'municipio' => 'Porto Alegre', 'codigo_municipio_ibge' => '4314902',
        ])]);
        $this->booking('e', 'saida_prevista', '2026-11-03', shipper: 'CNPJ 11.222.333/0001-81');

        $withoutLookup = app(PendingTakers::class)('2026-11-01', '2026-11-05');
        $withLookup = app(PendingTakers::class)('2026-11-01', '2026-11-05', suggest: true);

        $this->assertSame('', $withoutLookup[0]['endereco']);
        $this->assertSame(
            ['ACME LTDA', 'RUA DAS FLORES', '55', 'CENTRO', '90010000', 'PORTO ALEGRE', 'RS', '4314902'],
            array_map(fn ($key) => strtoupper($withLookup[0][$key]), ['nome', 'endereco', 'numero', 'bairro', 'cep', 'cidade', 'uf', 'ibge']),
        );
        Http::assertSentCount(1);
    }

    private function booking(string $mode, string $dateColumn, string $date, string $shipper = '', string $consignee = '', string $finalizado = 'a'): void
    {
        $id = DB::table('embarque')->insertGetId(['impor_expor' => $mode, $dateColumn => $date, 'finalizado' => $finalizado]);
        DB::table('bl')->insert(['embarque_id' => $id, 'shipper' => $shipper, 'consignee' => $consignee]);
    }

    private function person(string $name, string $cnpj, string $endereco, string $cep, string $bairro, string $ibge = '', bool $complete = false): void
    {
        $cityId = null;

        if ($complete) {
            $stateId = DB::table('estados')->insertGetId(['nome' => 'Rio Grande do Sul', 'uf' => 'RS']);
            $cityId = DB::table('cidades')->insertGetId(['nome' => 'PORTO ALEGRE', 'estados_id' => $stateId]);
        }

        DB::table('pessoa')->insert([
            'nome' => $name, 'cnpj' => $cnpj, 'endereco' => $endereco, 'cep' => $cep, 'bairro' => $bairro,
            'codigo_municipio_ibge' => $ibge, 'cidades_id' => $cityId,
        ]);
    }
}
