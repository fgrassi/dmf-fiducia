<?php

namespace Tests\Feature\Filament;

use App\Actions\Cobranca\Nfse\AddressLookup;
use App\Actions\ConsultaCnpj\RegisterCnpjReview;
use App\Filament\Resources\CnpjReviews\Pages\ListCnpjReviews;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\ConsultaCnpj;
use App\Models\Pessoa;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CnpjReviewResourceTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-11');
        DB::table('pessoa')->insert([
            ['pessoa_id' => 1, 'nome' => 'Com embarque', 'cnpj' => '111'],
            ['pessoa_id' => 2, 'nome' => 'Sem embarque', 'cnpj' => '222'],
            ['pessoa_id' => 3, 'nome' => 'Embarque antigo', 'cnpj' => '333'],
        ]);
        DB::table('embarque')->insert([
            ['impor_expor' => 'i', 'cliente_id' => 1, 'saida_prevista' => '2026-10-01'],
            ['impor_expor' => 'e', 'cliente_id' => 3, 'saida_prevista' => '2026-05-01'],
        ]);
    }

    public function test_validity_follows_the_fortnight_rule(): void
    {
        $validUntil = fn (int $status) => app(RegisterCnpjReview::class)->validUntil($status)->toDateString();

        // dia 11 (até 15): OK = hoje+90d no dia 15; demais = hoje+60d no fim do mês
        $this->assertSame('2027-02-15', $validUntil(ConsultaCnpj::OK));
        $this->assertSame('2027-01-31', $validUntil(ConsultaCnpj::RESTRICTED));

        // dia 20 (depois do 15): OK = hoje+90d no fim do mês; demais = hoje+60d no dia 15
        $this->travelTo('2026-11-20');
        $this->assertSame('2027-02-28', $validUntil(ConsultaCnpj::OK));
        $this->assertSame('2027-01-15', $validUntil(ConsultaCnpj::REVIEW));
    }

    public function test_new_review_deactivates_the_previous_one_and_expires_with_the_date(): void
    {
        $register = app(RegisterCnpjReview::class);
        $register(1, ConsultaCnpj::RESTRICTED, 'Pendência', 7);
        $register(1, ConsultaCnpj::OK, null, 7);

        $this->assertSame([0, 1], ConsultaCnpj::orderBy('consulta_cnpj_id')->pluck('ativo')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(ConsultaCnpj::OK, Pessoa::find(1)->cnpjStatus());
        $this->assertSame(ConsultaCnpj::NONE, Pessoa::find(2)->cnpjStatus());

        $this->travelTo('2027-02-16');
        $this->assertSame(ConsultaCnpj::EXPIRED, Pessoa::find(1)->cnpjStatus());
    }

    public function test_lists_only_companies_with_shipments_in_the_last_three_months(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ListCnpjReviews::class)
            ->assertCanSeeTableRecords(Pessoa::whereKey(1)->get())
            ->assertCanNotSeeTableRecords(Pessoa::whereKey([2, 3])->get());
    }

    public function test_changes_status_from_the_table_and_registers_any_company(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        Livewire::test(ListCnpjReviews::class)
            ->callTableAction('review', Pessoa::find(1), ['status' => ConsultaCnpj::REVIEW, 'observacao' => 'Revisar sócios'])
            ->assertHasNoTableActionErrors()
            ->callAction('register', ['pessoa_id' => 2, 'status' => ConsultaCnpj::OK])
            ->assertHasNoFormErrors();

        $first = ConsultaCnpj::where('pessoa_id', 1)->sole();
        $this->assertSame([ConsultaCnpj::REVIEW, 'Revisar sócios', $user->usuario_id, '2027-01-31'], [(int) $first->status, $first->observacao, (int) $first->created_by, $first->data_consulta->toDateString()]);
        $this->assertSame(ConsultaCnpj::OK, (int) ConsultaCnpj::where('pessoa_id', 2)->sole()->status);
    }

    public function test_shows_the_company_registration_from_open_cnpj(): void
    {
        $this->actingAs($this->user());
        DB::table('pessoa')->where('pessoa_id', 1)->update(['cnpj' => '06.990.590/0001-23']);
        Http::fake(['api.opencnpj.org/06990590000123' => Http::response([
            'cnpj' => '06990590000123',
            'razao_social' => 'Empresa Teste Ltda.',
            'situacao_cadastral' => 'Ativa',
            'cep' => '01310100',
            'nome_fantasia' => 'Teste Cargo',
            'telefones' => [['ddd' => '11', 'numero' => '40000000']],
            'cnae_principal' => '5211701',
            'cnaes_secundarios' => ['5212500'],
            'tipo_logradouro' => 'AVENIDA',
            'logradouro' => 'PAULISTA',
            'numero' => '1000',
            'municipio' => 'SAO PAULO',
            'uf' => 'SP',
        ])]);

        Livewire::test(ListCnpjReviews::class)
            ->mountTableAction('openCnpj', Pessoa::find(1))
            ->assertMountedActionModalSee('Empresa Teste Ltda.')
            ->assertMountedActionModalSee('Ativa')
            ->assertMountedActionModalSee('06.990.590/0001-23')
            ->assertMountedActionModalSeeHtml(CompanyResource::getUrl('edit', ['record' => 1]))
            ->assertMountedActionModalSee('01310-100')
            ->assertMountedActionModalSee('AVENIDA PAULISTA')
            ->assertMountedActionModalSee('SAO PAULO - SP')
            ->assertMountedActionModalSee('Teste Cargo')
            ->assertMountedActionModalSee('5212500')
            ->assertMountedActionModalDontSee('Telefones')
            ->assertMountedActionModalDontSee('40000000');
    }

    public function test_shows_the_error_when_open_cnpj_does_not_find_the_company(): void
    {
        $this->actingAs($this->user());
        DB::table('pessoa')->where('pessoa_id', 1)->update(['cnpj' => '06990590000123']);
        Http::fake(['api.opencnpj.org/*' => Http::response([], 404)]);

        Livewire::test(ListCnpjReviews::class)
            ->mountTableAction('openCnpj', Pessoa::find(1))
            ->assertMountedActionModalSee('CNPJ não encontrado na OpenCNPJ.');
    }

    public function test_header_button_looks_up_a_typed_cnpj(): void
    {
        $this->actingAs($this->user());
        Http::fake(['api.opencnpj.org/06990590000123' => Http::response(['cnpj' => '06990590000123', 'razao_social' => 'Empresa Teste Ltda.', 'cep' => '01310100'])]);

        Livewire::test(ListCnpjReviews::class)
            ->mountAction('lookup')
            ->fillForm(['cnpj' => '123'])
            ->callAction(TestAction::make('search')->schemaComponent('cnpj'))
            ->assertNotified('CNPJ inválido.')
            ->assertMountedActionModalDontSee('Empresa Teste Ltda.')
            ->fillForm(['cnpj' => '06.990.590/0001-23'])
            ->assertMountedActionModalDontSee('Empresa Teste Ltda.')
            ->callAction(TestAction::make('search')->schemaComponent('cnpj'))
            ->assertMountedActionModalSee('Empresa Teste Ltda.')
            ->assertMountedActionModalSee('01310-100')
            ->assertMountedActionModalDontSeeHtml('/edit');
    }

    public function test_company_name_links_to_the_company_registration(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ListCnpjReviews::class)
            ->assertSeeHtml(CompanyResource::getUrl('edit', ['record' => 1]));
    }

    public function test_open_cnpj_lookup_is_cached_for_one_day_but_errors_are_not(): void
    {
        $this->actingAs($this->user());
        Http::fake([
            'api.opencnpj.org/06990590000123' => Http::response(['cnpj' => '06990590000123', 'razao_social' => 'Empresa Teste Ltda.']),
            'api.opencnpj.org/11222333000181' => Http::sequence()->push([], 429)->push(['cnpj' => '11222333000181', 'razao_social' => 'Outra']),
        ]);
        $lookup = app(AddressLookup::class);

        $lookup->openCnpjData('06.990.590/0001-23');
        $lookup->openCnpjData('06990590000123');
        Http::assertSentCount(1);

        $this->assertNotNull($lookup->openCnpjData('11222333000181')['erro']);
        $this->assertNull($lookup->openCnpjData('11222333000181')['erro']);
        Http::assertSentCount(3);

        $this->travel(25)->hours();
        $lookup->openCnpjData('06990590000123');
        Http::assertSentCount(4);
    }

    public function test_requires_financial_and_import_credentials(): void
    {
        $this->actingAs($this->user('financial'));
        Livewire::test(ListCnpjReviews::class)->assertForbidden();

        $this->actingAs($this->user('financial,import', 'bia'));
        Livewire::test(ListCnpjReviews::class)->assertSuccessful();
    }

    private function user(string $credentials = 'financial,import', string $login = 'ana'): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);

        return User::find($id);
    }
}
