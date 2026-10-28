<?php

namespace Tests\Feature\Filament;

use App\Actions\Companies\SaveCompany;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\FollowUps;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ManageCompanyContacts;
use App\Models\Pessoa;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CompanyResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_company_with_types_carriers_office_and_history(): void
    {
        $this->actingAs($this->user('empresa'));
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Brasil']);
        DB::table('estados')->insert(['estados_id' => 1, 'paises_id' => 1, 'nome' => 'Santa Catarina']);
        DB::table('cidades')->insert(['cidades_id' => 1, 'estados_id' => 1, 'nome' => 'Itajaí']);
        DB::table('tipo_pessoa')->insert([['tipo_pessoa_id' => 3, 'nome' => 'Cliente']]);
        DB::table('sedes')->insert(['id' => 1, 'nome' => 'Itajaí']);
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'Maersk']);

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'nome' => 'ACME',
                'razao_social' => 'ACME LTDA',
                'cnpj' => '11222333000181',
                'paises_id' => 1,
                'estados_id' => 1,
                'cidades_id' => 1,
                'codigo_municipio_ibge' => '4208203',
                'bairro' => 'Centro',
                'cep' => '88300000',
                'endereco' => 'Rua A, 1',
                'tipo_empresa' => [3],
                'armadores_permitidos' => [1],
                'pessoa_sede' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Pessoa::sole();
        $this->assertSame('ACME', $company->nome);
        $this->assertNotNull($company->created_by);
        $this->assertSame(1, DB::table('pessoa_tipo_pessoa')->where('pessoa_id', $company->pessoa_id)->where('tipo_pessoa_id', 3)->count());
        $this->assertSame(1, DB::table('pessoa_armador')->where('pessoa_id', $company->pessoa_id)->where('armador_id', 1)->count());
        $this->assertSame(1, DB::table('pessoa_sede')->where('pessoa_id', $company->pessoa_id)->where('sede_id', 1)->count());
        $this->assertSame('Cadastro realizado', DB::table('historico_pessoa')->where('pessoa_id', $company->pessoa_id)->value('assunto'));
    }

    public function test_update_replaces_links_and_keeps_hidden_type(): void
    {
        $this->actingAs($this->user('empresa'));
        $company = Pessoa::forceCreate(['nome' => 'ACME', 'cidades_id' => null]);
        DB::table('pessoa_tipo_pessoa')->insert([['pessoa_id' => $company->pessoa_id, 'tipo_pessoa_id' => 1], ['pessoa_id' => $company->pessoa_id, 'tipo_pessoa_id' => 3]]);

        app(SaveCompany::class)(['nome' => 'ACME 2', 'tipo_empresa' => [4], 'armadores_permitidos' => [], 'pessoa_sede' => null], 1, $company);

        $this->assertSame('ACME 2', $company->fresh()->nome);
        $this->assertEqualsCanonicalizing([1, 4], DB::table('pessoa_tipo_pessoa')->where('pessoa_id', $company->pessoa_id)->pluck('tipo_pessoa_id')->all());
        $this->assertSame('Atualização dos dados', DB::table('historico_pessoa')->where('pessoa_id', $company->pessoa_id)->value('assunto'));
    }

    public function test_deletes_company_with_links(): void
    {
        $this->actingAs($this->user('empresa'));
        $company = Pessoa::forceCreate(['nome' => 'ACME']);
        DB::table('pessoa_tipo_pessoa')->insert(['pessoa_id' => $company->pessoa_id, 'tipo_pessoa_id' => 3]);

        Livewire::test(ListCompanies::class)
            ->callTableAction(DeleteAction::class, $company);

        $this->assertNull($company->fresh());
        $this->assertSame(0, DB::table('pessoa_tipo_pessoa')->count());
    }

    public function test_searches_city_code_on_ibge(): void
    {
        $this->actingAs($this->user('empresa'));
        $company = Pessoa::forceCreate(['nome' => 'ACME']);
        Http::fake(['*' => Http::response([['id' => 4208203, 'nome' => 'Itajaí', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'SC']]]]])]);

        Livewire::test(EditCompany::class, ['record' => $company->pessoa_id])
            ->callAction(TestAction::make('searchCity')->schemaComponent('codigo_municipio_ibge', schema: 'form'), ['city' => '4208203'])
            ->assertSet('data.codigo_municipio_ibge', '4208203');
    }

    public function test_lists_follow_ups_newest_first(): void
    {
        $this->actingAs($this->user('empresa'));
        $company = Pessoa::forceCreate(['nome' => 'ACME']);
        $userId = DB::table('usuario')->value('usuario_id');
        DB::table('cmn_client_fup')->insert(['ref_pessoa' => $company->pessoa_id, 'descr' => 'Ligou', 'ref_user' => $userId, 'created_at' => '2026-01-01 10:00:00', 'data_ocorrencia' => '2026-01-01', 'status' => 1]);
        DB::table('fu_process')->insert(['id' => 1, 'ref_pessoa' => $company->pessoa_id]);
        DB::table('fu_histories')->insert(['ref_process' => 1, 'comentarios' => 'Proposta enviada', 'created_at' => '2026-02-01 10:00:00', 'created_by' => $userId]);

        Livewire::test(FollowUps::class, ['record' => $company->pessoa_id])
            ->assertSeeInOrder(['Proposta enviada', 'Ligou']);
    }

    public function test_manages_contacts(): void
    {
        $this->actingAs($this->user('empresa'));
        $company = Pessoa::forceCreate(['nome' => 'ACME', 'domain' => 'acme.com']);
        DB::table('frase_categoria')->insert(['id' => 1, 'nome' => 'Geral']);
        $component = fn () => Livewire::test(ManageCompanyContacts::class, ['record' => $company->pessoa_id]);

        $component()
            ->callAction(TestAction::make(CreateAction::class)->table(), ['nome' => 'Joao', 'email' => 'joao@acme.com', 'usuario_padrao' => 's', 'receber_avisos' => 's', 'receber_relatorio' => 'n', 'receber_documentos' => 'n', 'receber_cobranca' => 't', 'receber_avisos_demurrage' => 'n', 'tipos' => [7], 'categorias' => [1]])
            ->assertHasNoFormErrors();

        $contact = $company->contacts()->sole();
        $this->assertSame(1, (int) $contact->status);
        $this->assertSame([7], DB::table('pessoa_usuario_tipo_usuario')->pluck('tipo_usuario_id')->all());
        $this->assertSame([1], DB::table('usuario_frase_categoria')->where('ref_usuario', $contact->usuario_id)->pluck('ref_categoria_frase')->all());

        $component()
            ->callAction(TestAction::make(CreateAction::class)->table(), ['nome' => 'Ext', 'email' => 'ext@outro.com', 'justificativa' => 'Parceiro', 'usuario_padrao' => 'n', 'receber_avisos' => 'n', 'receber_relatorio' => 'n', 'receber_documentos' => 'n', 'receber_cobranca' => 'n', 'receber_avisos_demurrage' => 'n'])
            ->assertHasNoFormErrors();

        $this->assertSame(0, (int) $company->contacts()->where('email', 'ext@outro.com')->value('usuario.status'));
        $this->assertSame(1, DB::table('justificativa_contato_pessoa')->count());
        $this->assertSame(1, DB::table('cmn_messages_recipients')->count());

        $pending = $company->contacts()->where('email', 'ext@outro.com')->sole();
        $component()
            ->callAction(TestAction::make(EditAction::class)->table($pending), ['nome' => 'Ext 2', 'email' => 'ext@outro.com', 'usuario_padrao' => 'n', 'receber_avisos' => 'n', 'receber_relatorio' => 'n', 'receber_documentos' => 'n', 'receber_cobranca' => 'n', 'receber_avisos_demurrage' => 'n'])
            ->assertHasNoFormErrors();
        $this->assertSame(0, (int) $pending->fresh()->status);

        $component()->callAction(TestAction::make(DeleteAction::class)->table($contact));

        $this->assertSame(-1, (int) $contact->fresh()->status);
        $this->assertSame(['ext@outro.com'], $company->contacts()->where('usuario.status', '<>', -1)->pluck('email')->all());
    }

    public function test_requires_empresa_credential(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ListCompanies::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}
