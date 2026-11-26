<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\Pages\CreateEmbarque;
use App\Filament\Resources\Embarques\Pages\ListEmbarques;
use App\Models\Embarque;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BookingCreationFixture;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CreateBookingPageTest extends TestCase
{
    use BookingCreationFixture;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBookingFixture();
        $this->actingAs($this->author);
    }

    #[DataProvider('types')]
    public function test_each_booking_type_has_its_creation_page(string $operation, string $mode, string $title): void
    {
        $this->get(EmbarqueResource::getUrl('create', ['operacao' => $operation, 'tipo' => $mode]))
            ->assertOk()
            ->assertSee($title);
    }

    public static function types(): array
    {
        return [
            ['i', 'm', 'Importação marítimo'],
            ['e', 'm', 'Exportação marítimo'],
            ['i', 'a', 'Importação aéreo'],
            ['e', 'a', 'Exportação aéreo'],
        ];
    }

    public function test_list_offers_only_the_operations_the_user_sees(): void
    {
        DB::table('usuario')->where('usuario_id', $this->author->getKey())->update(['credentials' => 'booking,export']);
        $this->actingAs($this->author->refresh());

        Livewire::test(ListEmbarques::class)
            ->assertActionExists('nova_em')
            ->assertActionDoesNotExist('nova_im');
        $this->get(EmbarqueResource::getUrl('create', ['operacao' => 'i', 'tipo' => 'm']))
            ->assertForbidden();
    }

    public function test_choosing_the_client_brings_its_default_contacts_and_the_page_creates_the_booking(): void
    {
        $this->contactOf($this->client, 7, 'n');

        $page = Livewire::test(CreateEmbarque::class, ['operacao' => 'i', 'tipo' => 'm'])
            ->fillForm(['cliente_id' => $this->client])
            ->assertSchemaStateSet(['contacts.cliente' => [$this->contact]])
            ->fillForm(collect($this->bookingData())->except(['contacts', 'goods', 'cliente_id'])->all())
            ->fillForm(['goods' => [['nome' => 'Peças', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0]]])
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Embarque::sole();
        $page->assertRedirect(EmbarqueResource::getUrl('view', ['record' => $booking]));
        $this->assertDatabaseHas('embarque_contato_cliente', ['embarque_id' => $booking->getKey(), 'usuario_id' => $this->contact]);
        $this->assertDatabaseHas('mercadoria', ['embarque_id' => $booking->getKey()]);
    }

    public function test_transit_time_fills_the_eta_from_the_etd(): void
    {
        Livewire::test(CreateEmbarque::class, ['operacao' => 'i', 'tipo' => 'm'])
            ->fillForm(['saida_prevista' => '2026-12-01'])
            ->fillForm(['transit' => 35])
            ->assertSchemaStateSet(['chegada_prevista' => '2027-01-05']);
    }

    public function test_a_new_payer_and_a_new_final_destination_are_registered_from_the_export_form(): void
    {
        DB::table('paises')->insert(['paises_id' => 4, 'nome' => 'Estados Unidos']);
        $state = $this->row('estados', ['nome' => 'Florida', 'paises_id' => 4]);
        $city = $this->row('cidades', ['nome' => 'Miami', 'estados_id' => $state]);
        $destination = ['pais_id' => 4, 'estado_id' => $state, 'cidade_id' => $city];

        $page = Livewire::test(CreateEmbarque::class, ['operacao' => 'e', 'tipo' => 'm'])
            ->fillForm(['cliente_id' => $this->client, 'flag_fi' => 1])
            ->callAction(TestAction::make('createOption')->schemaComponent('destino_final_id'), $destination)
            ->assertHasFormErrors(['endereco' => 'required', 'zip_code' => 'required'])
            ->unmountAction()
            ->callAction(TestAction::make('createOption')->schemaComponent('destino_final_id'), [...$destination, 'endereco' => '1 Port Blvd', 'zip_code' => '33132'])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('createOption')->schemaComponent('pessoa_id_pagador'), ['nome' => 'PAGADOR NOVO', 'razao_social' => 'PAGADOR NOVO LTDA', 'paises_id' => 4, 'cidades_id' => $city])
            ->assertHasNoFormErrors();

        $address = DB::table('destino_final')->sole();
        $payer = DB::table('pessoa')->where('nome', 'PAGADOR NOVO')->value('pessoa_id');
        $this->assertEquals([$this->client, $state, '33132'], [$address->pessoa_id, $address->estado_id, $address->zip_code]);
        $this->assertDatabaseHas('pessoa_tipo_pessoa', ['pessoa_id' => $payer, 'tipo_pessoa_id' => 3]);
        $this->assertDatabaseHas('historico_pessoa', ['pessoa_id' => $payer, 'assunto' => 'Cadastro realizado']);
        $page->assertSchemaStateSet(['destino_final_id' => $address->destino_final_id, 'pessoa_id_pagador' => $payer]);
    }

    public function test_a_refusal_is_shown_and_nothing_is_saved(): void
    {
        DB::table('pessoa')->where('pessoa_id', $this->client)->update(['ctr_blacklist' => 1]);

        Livewire::test(CreateEmbarque::class, ['operacao' => 'i', 'tipo' => 'm'])
            ->fillForm(['cliente_id' => $this->client])
            ->fillForm(collect($this->bookingData())->except(['contacts', 'goods', 'cliente_id'])->all())
            ->call('create')
            ->assertNotified('O Cliente selecionado está na blacklist do sistema.');

        $this->assertSame(0, Embarque::count());
    }

    public function test_maritime_export_creates_after_confirming_the_pickup_and_delivery_places(): void
    {
        $contact = $this->contactOf($this->client, 3);
        $this->row('usuario', ['nome' => 'Fecha', 'status' => 1, 'credentials' => 'export,fechamento']);
        DB::table('paises')->insert(['paises_id' => 25, 'nome' => 'África do Sul']);

        Livewire::test(CreateEmbarque::class, ['operacao' => 'e', 'tipo' => 'm'])
            ->fillForm(['cliente_id' => $this->client, 'pais_id' => 25])
            ->fillForm([
                'vendedor_id' => $this->seller, 'booking' => 'EXP-1', 'pessoa_id_representante' => $this->agent, 'de' => 'Fecha',
                'tipo_fechamento' => 'o', 'armador_id' => $this->row('armador', ['nome' => 'MSC']), 'via' => 'Direto', 'free_time_detention' => 7,
                'data_validade' => '2027-01-31', 'porto_origem_id' => $this->destination, 'porto_destino_id' => $this->origin,
            ])
            ->assertSchemaStateSet(['contacts.cliente' => [$contact]])
            ->callAction(TestAction::make('create')->schemaComponent('form-actions', schema: 'content'))
            ->assertRedirect();

        $this->assertDatabaseHas('embarque', ['booking' => 'EXP-1', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'de' => 'Fecha']);
    }
}
