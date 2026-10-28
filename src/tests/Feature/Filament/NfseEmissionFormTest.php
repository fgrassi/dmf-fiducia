<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Charges\Schemas\NfseEmissionForm;
use App\Models\FinChargesNfse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NfseEmissionFormTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $stateId = DB::table('estados')->insertGetId(['nome' => 'Rio Grande do Sul', 'uf' => 'RS']);
        $cityId = DB::table('cidades')->insertGetId(['nome' => 'PORTO ALEGRE', 'estados_id' => $stateId]);
        DB::table('pessoa')->insert([
            'pessoa_id' => 5, 'nome' => 'ACME', 'cnpj' => '11.222.333/0001-81', 'endereco' => 'Av. Brasil, 120', 'bairro' => 'Centro',
            'cep' => '90010000', 'codigo_municipio_ibge' => '4314902', 'cidades_id' => $cityId, 'email' => 'fin@acme.com',
        ]);
    }

    public function test_generate_form_brings_the_client_address_and_contact_to_be_reviewed(): void
    {
        $charge = $this->charge(['total_value' => 100], ['cliente_id' => 5]);

        $values = NfseEmissionForm::defaults($charge, NfseEmissionForm::GENERATE);

        $this->assertSame(
            ['90010-000', 'Av. Brasil', '120', 'Centro', '4314902', 'RS', 'fin@acme.com'],
            [$values['endereco_cep'], $values['endereco_logradouro'], $values['endereco_numero'], $values['endereco_bairro'],
                $values['endereco_codigo_municipio'], $values['endereco_uf'], $values['email']],
        );
    }

    public function test_settlement_form_does_not_ask_for_address(): void
    {
        $charge = $this->charge(['total_value' => 100], ['cliente_id' => 5]);

        $values = NfseEmissionForm::defaults($charge, NfseEmissionForm::SETTLEMENT);

        $this->assertArrayNotHasKey('endereco_cep', $values);
    }

    public function test_substitution_suggests_the_current_value_when_the_slice_changed(): void
    {
        $charge = $this->charge(['total_value' => 150], ['cliente_id' => 5]);
        $original = $this->authorizedNote($charge, 100.00);

        $values = NfseEmissionForm::defaults($charge, NfseEmissionForm::SUBSTITUTE, $original);

        $this->assertSame(['atual', '150,00', '99'], [$values['valor_origem'], $values['valor_servicos'], $values['c_motivo']]);
    }

    public function test_substitution_keeps_the_original_value_when_nothing_changed(): void
    {
        $charge = $this->charge(['total_value' => 100], ['cliente_id' => 5]);
        $original = $this->authorizedNote($charge, 100.00);

        $values = NfseEmissionForm::defaults($charge, NfseEmissionForm::SUBSTITUTE, $original);

        $this->assertSame(['original', '100,00'], [$values['valor_origem'], $values['valor_servicos']]);
    }

    private function authorizedNote($charge, float $value): FinChargesNfse
    {
        $id = DB::table('fin_charges_nfse')->insertGetId([
            'ref_charge' => $charge->id, 'id_externo' => 'BKG10-1', 'nfse_uuid' => 'u1', 'status' => 'autorizada', 'chave_acesso' => 'CH',
            'valor_servicos' => $value, 'tomador_json' => json_encode(['tipo' => 'pj', 'cpf_cnpj' => '11222333000181', 'nome' => 'ACME', 'pessoa_id' => 'cliente']),
        ]);

        return FinChargesNfse::find($id);
    }
}
