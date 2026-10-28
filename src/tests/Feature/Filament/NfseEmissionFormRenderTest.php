<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Charges\Pages\ViewCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NfseEmissionFormRenderTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    public function test_generate_modal_is_prefilled_with_the_client_address_and_validates_it(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->user());
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'ACME', 'cnpj' => '11.222.333/0001-81', 'endereco' => 'Av. Brasil, 120', 'bairro' => 'Centro']);
        DB::table('fin_clients_boleto')->insert(['ref_client' => 5, 'prazo_exp' => 30]);
        $charge = $this->charge(['total_value' => 100], ['cliente_id' => 5, 'tipo_embarque' => 'm']);

        Livewire::test(ViewCharge::class, ['record' => $charge->id])
            ->mountAction('gerarNfse')
            ->assertActionDataSet(fn (array $data) => $data['nfse']['endereco_logradouro'] === 'Av. Brasil' && $data['nfse']['endereco_numero'] === '120')
            ->fillForm(['nfse.endereco_cep' => '9001', 'nfse.endereco_bairro' => str_repeat('b', 61)])
            ->callMountedAction()
            ->assertHasFormErrors(['nfse.endereco_cep' => 'regex', 'nfse.endereco_bairro' => 'max']);
    }

    public function test_substitution_modal_defaults_to_the_current_value_and_switches_back_to_the_original(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->user());
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'ACME', 'cnpj' => '11.222.333/0001-81', 'endereco' => 'Av. Brasil, 120', 'bairro' => 'Centro']);
        $charge = $this->charge(['total_value' => 150], ['cliente_id' => 5]);
        $noteId = DB::table('fin_charges_nfse')->insertGetId([
            'ref_charge' => $charge->id, 'id_externo' => 'BKG10-1', 'nfse_uuid' => 'u1', 'status' => 'autorizada', 'chave_acesso' => 'CH', 'valor_servicos' => 100,
            'tomador_json' => json_encode(['tipo' => 'pj', 'cpf_cnpj' => '11222333000181', 'nome' => 'ACME', 'pessoa_id' => 'cliente']),
        ]);

        Livewire::test(ViewCharge::class, ['record' => $charge->id])
            ->mountAction('substituirNfse', ['id' => $noteId])
            ->assertActionDataSet(fn (array $data) => $data['nfse']['valor_origem'] === 'atual' && $data['nfse']['valor_servicos'] === '150,00')
            ->fillForm(['nfse.valor_origem' => 'original'])
            ->assertActionDataSet(fn (array $data) => $data['nfse']['valor_servicos'] === '100,00');
    }

    public function test_changing_the_operation_recomputes_the_current_value_of_that_slice(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->user());
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'ACME', 'cnpj' => '11.222.333/0001-81', 'endereco' => 'Av. Brasil, 120', 'bairro' => 'Centro']);
        $charge = $this->charge(['total_value' => 100], ['cliente_id' => 5]);
        $this->item($charge, 60, expense: 500, stale: 60.0);
        $this->item($charge, 40, expense: 501, stale: 40.0);
        $services = DB::table('config_nfse_operacao')->insertGetId(['codigo_operacao' => 'EXPO', 'impor_expor' => 'e', 'ordem' => 1, 'descricao_servico' => 'Taxas']);
        $residual = DB::table('config_nfse_operacao')->insertGetId(['codigo_operacao' => 'EXPO_NI', 'impor_expor' => 'e', 'ordem' => 2, 'descricao_servico' => 'Demais']);
        DB::table('config_nfse_operacao_despesa')->insert(['config_nfse_operacao_id' => $services, 'fin_expenses_id' => 500]);
        $noteId = DB::table('fin_charges_nfse')->insertGetId([
            'ref_charge' => $charge->id, 'id_externo' => 'BKG10-1', 'nfse_uuid' => 'u1', 'status' => 'autorizada', 'chave_acesso' => 'CH',
            'valor_servicos' => 100, 'config_nfse_operacao_id' => $services,
            'tomador_json' => json_encode(['tipo' => 'pj', 'cpf_cnpj' => '11222333000181', 'nome' => 'ACME', 'pessoa_id' => 'cliente']),
        ]);

        Livewire::test(ViewCharge::class, ['record' => $charge->id])
            ->mountAction('substituirNfse', ['id' => $noteId])
            ->assertActionDataSet(fn (array $data) => $data['nfse']['valor_servicos'] === '60,00')
            ->fillForm(['nfse.config_nfse_operacao_id' => $residual])
            ->assertActionDataSet(fn (array $data) => $data['nfse']['valor_servicos'] === '40,00' && $data['nfse']['valor_origem'] === 'atual');
    }
}
