<?php

namespace Tests\Feature;

use App\Actions\Cobranca\Nfse\AddressLookup;
use App\Models\ConfigNfseOperacao;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NfseSettingsTest extends TestCase
{
    public function test_expense_link_blocks_duplicate_and_same_mode_conflict(): void
    {
        Schema::create('config_nfse_operacao', function (Blueprint $t) {
            $t->increments('config_nfse_operacao_id');
            $t->string('codigo_operacao');
            $t->string('nome')->nullable();
            $t->string('impor_expor')->nullable();
            $t->boolean('ativo')->default(true);
        });
        Schema::create('config_nfse_operacao_despesa', function (Blueprint $t) {
            $t->increments('config_nfse_operacao_despesa_id');
            $t->integer('config_nfse_operacao_id');
            $t->integer('fin_expenses_id');
        });

        $impo = ConfigNfseOperacao::create(['codigo_operacao' => 'IMPO', 'nome' => 'Importação', 'impor_expor' => 'i']);
        $desconsolida = ConfigNfseOperacao::create(['codigo_operacao' => 'IMPO_DESC', 'impor_expor' => 'i']);
        $expo = ConfigNfseOperacao::create(['codigo_operacao' => 'EXPO', 'impor_expor' => 'e']);
        $inactive = ConfigNfseOperacao::create(['codigo_operacao' => 'OLD', 'impor_expor' => 'i', 'ativo' => false]);
        $impo->expenseLinks()->create(['fin_expenses_id' => 10]);
        $inactive->expenseLinks()->create(['fin_expenses_id' => 20]);

        $this->assertSame('Despesa já vinculada a esta operação.', $impo->expenseLinkError(10));
        $this->assertSame('Despesa já vinculada à operação "IMPO — Importação" do mesmo modal.', $desconsolida->expenseLinkError(10));
        $this->assertNull($expo->expenseLinkError(10));
        $this->assertNull($desconsolida->expenseLinkError(20));
    }

    public function test_searches_cities_ignoring_accents_and_reads_uf(): void
    {
        Http::fake(['*/estados/RS/municipios' => Http::response([
            ['id' => 4314902, 'nome' => 'Porto Alegre', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'RS']]]],
            ['id' => 4316808, 'nome' => 'Santa Cruz do Sul', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'RS']]]],
            ['id' => 4300406, 'nome' => 'Alegrete', 'microrregiao' => null, 'regiao-imediata' => ['regiao-intermediaria' => ['UF' => ['sigla' => 'RS']]]],
        ])]);

        $found = app(AddressLookup::class)->searchCities('alégre', 'rs');

        $this->assertSame([
            ['id' => '4314902', 'nome' => 'Porto Alegre', 'uf' => 'RS'],
            ['id' => '4300406', 'nome' => 'Alegrete', 'uf' => 'RS'],
        ], $found);
        $this->assertSame([], app(AddressLookup::class)->searchCities('a', 'RS'));
    }
}
