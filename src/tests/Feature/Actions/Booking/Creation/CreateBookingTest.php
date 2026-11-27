<?php

namespace Tests\Feature\Actions\Booking\Creation;

use App\Actions\Booking\Creation\CreateBooking;
use App\Actions\Booking\Creation\NextBookingNumber;
use App\Actions\Booking\Creation\QuotationPrefill;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BookingCreationFixture;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CreateBookingTest extends TestCase
{
    use BookingCreationFixture;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBookingFixture();
    }

    public function test_maritime_import_saves_the_booking_and_everything_the_legacy_creates_with_it(): void
    {
        DB::table('pessoa')->where('pessoa_id', $this->client)->update(['ctr_liberacao_hbl_origem' => 's']);
        $ship = $this->row('navio', ['nome' => 'MSC ANNA']);
        $outsider = $this->row('usuario', ['nome' => 'De outra empresa', 'status' => 1]);

        $booking = $this->create($this->bookingData([
            'navio_id' => $ship,
            'vg' => '12N',
            'saida_prevista' => '2026-12-01',
            'chegada_prevista' => '2027-01-10',
            'ce_mercante' => '123',
            'contacts' => ['cliente' => [$this->contact, $outsider]],
            'goods' => [['nome' => 'Peças', 'quantidade' => 2, 'tipo_container_id' => $this->equipment, 'quimico' => 0]],
        ]));

        $this->assertSame(['i', 'm', 'n'], [$booking->impor_expor, $booking->tipo_embarque, $booking->finalizado]);
        $this->assertSame(['2026-12-01', '2027-01-10', -1], [$booking->etd_inicial, $booking->eta_inicial, (int) $booking->ctr_seguro]);
        $this->assertNotNull($booking->data_ce_mercante);
        $this->assertSame([$this->contact], DB::table('embarque_contato_cliente')->pluck('usuario_id')->all());
        $this->assertDatabaseHas('mercadoria', ['embarque_id' => $booking->getKey(), 'nome' => 'Peças']);
        $this->assertDatabaseHas('bl', ['embarque_id' => $booking->getKey(), 'status' => 'aberto', 'vessel' => 'MSC ANNA - 12N']);
        $this->assertDatabaseHas('liberacao_embarque_origem', ['embarque_id' => $booking->getKey(), 'status' => 0]);
        $history = DB::table('rastreamento')->where(['ref_embarque' => $booking->getKey(), 'assunto' => 'Atualizado: Reserva', 'evento' => 'u'])->value('historico');
        foreach (['<p>Booking: IMP1-26</p>', '<p>Navio: MSC ANNA</p>', '<p>ETD: 01/12/2026</p>', '<p>Destino: RIO GRANDE ()(BRRIG)</p>', '<td>Peças</td><td>2</td>', '<td>TOTAL:</td><td></td><td>2,0000</td>'] as $expected) {
            $this->assertStringContainsString($expected, $history);
        }
        $this->assertDatabaseHas('hist_embarque', ['embarque_id' => $booking->getKey(), 'nsequencia' => 1, 'noperacao' => 'I']);
        $this->assertSame(1, Embarque::count());
    }

    public function test_matrix_launches_carrier_agent_client_and_broker_expenses_and_the_guarantee_deposit(): void
    {
        $carrier = $this->row('armador', ['nome' => 'MSC']);
        $broker = $this->row('despachante', ['nome' => 'DESP']);
        $carrierPort = $this->row('cmn_armador_porto', ['ref_armador' => $carrier, 'ref_porto' => $this->destination]);
        $brokerPort = $this->row('despachante_porto', ['id_despachante' => $broker, 'id_porto' => $this->destination]);
        $other = $this->row('tipo_container', ['nome' => "40' HC", 'tipo_embarque' => 'm']);
        [$thc, $skipped, $agency, $freight, $clearance] = array_map(
            fn (string $name) => $this->row('fin_expenses', ['name' => $name, 'operation_type' => 'b']),
            ['THC', 'OUTRO PAIS', 'AGENCY', 'FREIGHT', 'CLEARANCE'],
        );
        $base = ['created_at' => '2026-01-01', 'created_by' => 1, 'due_from' => '2026-01-01', 'ref_currency' => 2, 'billing' => 'c'];
        $matrix = [...$base, 'ref_armador_porto' => $carrierPort, 'type' => 'i', 'imo_value' => 0, 'expense_value' => 100];
        $this->row('fin_expenses_matrix', [...$matrix, 'ref_expense' => $thc, 'ref_equipamento' => $this->equipment]);
        $this->row('fin_expenses_matrix', [...$matrix, 'ref_expense' => $skipped, 'pais_origem_id' => 99]);
        $this->row('fin_expenses_matrix', [...$matrix, 'ref_expense' => $skipped, 'ref_equipamento' => $other]);
        $this->row('fin_expenses_matrix', [...$matrix, 'ref_expense' => $skipped, 'due_to' => '2026-02-01']);
        $this->row('matriz_despesa_pessoa', [...$base, 'pessoa_id' => $this->agent, 'ref_expense' => $agency, 'expense_value' => 30]);
        $this->row('matriz_despesa_pessoa', [...$base, 'pessoa_id' => $this->client, 'ref_expense' => $freight, 'expense_value' => 900, 'impor_expor' => 'i', 'moeda_compra_id' => 2, 'valor_compra' => 700]);
        $this->row('matriz_despesa_despachante', [...$base, 'ref_despachante_porto' => $brokerPort, 'type' => 'i', 'ref_expense' => $clearance, 'expense_value' => 55]);
        $this->row('deposito_garantia', ['ref_armador' => $carrier, 'ref_equipment' => $this->equipment, 'valor' => 10, 'ref_currency' => 2]);
        $good = ['nome' => 'Peças', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0];
        DB::table('porto')->where('porto_id', $this->destination)->update(['despachante_id' => $broker]);

        $booking = $this->create($this->bookingData(['armador_id' => $carrier, 'garantee' => '3', 'goods' => [$good, $good]]));

        $expenses = DB::table('fin_expenses_values')->where('ref_embarque', $booking->getKey())->orderBy('ref_expense')->get()->keyBy('ref_expense');
        $this->assertSame([$thc, $agency, $freight, $clearance, 27], $expenses->keys()->sortBy(fn ($id) => array_search($id, [$thc, $agency, $freight, $clearance, 27]))->values()->all());
        $this->assertSame(5, DB::table('fin_expenses_values')->count(), 'a segunda mercadoria igual não duplica');
        $this->assertEquals([100, 3, 0], [$expenses[$thc]->buying_rate, $expenses[$thc]->ref_due_to, $expenses[$thc]->ctrl]);
        $this->assertEquals([30, 1], [$expenses[$agency]->buying_rate, $expenses[$agency]->ref_due_to]);
        $this->assertEquals([900, 700, 7], [$expenses[$freight]->selling_rate, $expenses[$freight]->buying_rate, $expenses[$freight]->ref_due_to]);
        $this->assertEquals([55, 5], [$expenses[$clearance]->buying_rate, $expenses[$clearance]->ref_due_to]);
        $this->assertEquals(300, $expenses[27]->selling_rate);
        $this->assertEqualsCanonicalizing(
            ['Revisão de Reserva: IMP1-26 (ACME)', 'Revisão de Reserva - Depósito de Garantia: IMP1-26'],
            $this->sentSubjects()->all(),
        );
    }

    public function test_dmf_broker_is_always_the_destination_port_default(): void
    {
        $default = $this->row('despachante', ['nome' => 'PADRAO']);

        $without = $this->create($this->bookingData(['despachante_dmf_id' => $default]));
        DB::table('porto')->where('porto_id', $this->destination)->update(['despachante_id' => $default]);
        $with = $this->create($this->bookingData(['booking' => 'IMP2-26', 'despachante_dmf_id' => 999]));

        $this->assertSame([null, $default], [$without->despachante_dmf_id, (int) $with->despachante_dmf_id]);
    }

    public function test_history_escapes_what_the_user_typed(): void
    {
        $booking = $this->create($this->bookingData(['via' => '<script>x</script>']));

        $this->assertStringContainsString('<p>Via: &lt;script&gt;x&lt;/script&gt;</p>', DB::table('rastreamento')->where('ref_embarque', $booking->getKey())->value('historico'));
    }

    public function test_contracted_insurance_creates_the_linked_insurance_booking(): void
    {
        $booking = $this->create($this->bookingData(['insurance' => 's', 'invoice' => 'INV9']));

        $insurance = Embarque::where('tipo_embarque', 's')->sole();
        $this->assertSame(1, (int) $booking->ctr_seguro);
        $this->assertSame(['SEG1-'.now()->format('y'), 'IMP1-26 / INV9', $booking->getKey()], [$insurance->booking, $insurance->invoice, (int) $insurance->reserva_vinculada_id]);
        $this->assertDatabaseHas('insurance_confirmation', ['ref_embarque' => $insurance->getKey(), 'control' => 0]);
        $this->assertSame(1, DB::table('bl')->count(), 'o seguro não tem BL');
    }

    #[DataProvider('modes')]
    public function test_client_with_mandatory_insurance_needs_a_justification_that_is_logged_and_mailed(string $mode): void
    {
        DB::table('pessoa')->where('pessoa_id', $this->client)->update(['seguro_obrigatorio' => 's']);

        $number = $mode === 'a' ? 'AIMP1-26' : 'IMP1-26';

        $booking = $this->create($this->bookingData(['booking' => $number, 'de' => 'Ana', 'justificativa_seguro' => 'Cliente tem apólice própria']), 'i', $mode);

        $this->assertDatabaseHas('rastreamento', ['ref_embarque' => $booking->getKey(), 'justificativa' => 'Cliente tem apólice própria']);
        $this->assertContains("[Aviso] Cliente com Seguro Obrigatório sem Reserva de Seguro - {$number}", $this->sentSubjects()->all());
    }

    public static function modes(): array
    {
        return ['marítimo' => ['m'], 'aéreo' => ['a']];
    }

    #[DataProvider('refusals')]
    public function test_refuses_an_invalid_booking_without_writing_anything(string $case, string $message): void
    {
        $data = $this->bookingData();
        match ($case) {
            'blacklist' => DB::table('pessoa')->where('pessoa_id', $this->client)->update(['ctr_blacklist' => 1]),
            'contact' => $data['contacts'] = [],
            'uncode' => DB::table('porto')->where('porto_id', $this->origin)->update(['uncode' => ' ']),
            'insurance' => DB::table('pessoa')->where('pessoa_id', $this->client)->update(['seguro_obrigatorio' => 's']),
            'agent' => $data['pessoa_id_representante'] = null,
            'reefer' => $data['goods'] = [['nome' => 'Frango', 'quantidade' => 1, 'tipo_container_id' => 8]],
            'typed' => $data['booking'] = 'IMP999-26',
            'free text' => $data['booking'] = 'QUALQUER',
            'air number' => $data['booking'] = 'AIMP1-26',
        };

        try {
            $this->create($data);
            $this->fail('A reserva foi criada.');
        } catch (DomainException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame(0, Embarque::count());
        $this->assertCount(0, $this->sentSubjects());
    }

    public static function refusals(): array
    {
        return [
            'blacklist' => ['blacklist', 'blacklist'],
            'sem contato' => ['contact', 'pelo menos um contato'],
            'porto sem UN/Code' => ['uncode', 'UN/Code'],
            'seguro obrigatório' => ['insurance', 'justifique'],
            'sem agente' => ['agent', 'Agente'],
            'reefer sem temperatura' => ['reefer', 'REEFER'],
            'sequência não emitida' => ['typed', 'Solicitar número'],
            'número digitado' => ['free text', 'Solicitar número'],
            'número aéreo no marítimo' => ['air number', 'Solicitar número'],
        ];
    }

    public function test_refuses_an_import_number_already_used(): void
    {
        $this->create($this->bookingData());

        $this->expectExceptionMessage('já foi utilizado');

        $this->create($this->bookingData());
    }

    public function test_user_limited_to_import_cannot_create_an_export(): void
    {
        DB::table('usuario')->where('usuario_id', $this->author->getKey())->update(['credentials' => 'booking,import']);
        $this->author->refresh();

        $this->expectExceptionMessage('não pode criar reservas desta operação');

        $this->create($this->bookingData(), 'e');
    }

    public function test_maritime_export_flags_ams_by_destination_country_and_syncs_the_quotation(): void
    {
        $south = $this->row('porto', ['nome' => 'DURBAN', 'uncode' => 'ZADUR', 'ref_pais' => 25, 'tipo' => 'p']);
        $quotation = $this->row('cotacao_expo', ['pessoa_id' => $this->client, 'porto_origem_id' => $this->destination, 'porto_destino_id' => $south, 'transit_time' => 30, 'armador_id' => 1, 'validade' => '2027-01-01']);
        $data = $this->exportData(['porto_destino_id' => $south, 'cotacao_expo_id' => $quotation, 'bl_direto' => 1, 'tipo_frete' => 'c', 'instrucao_especial' => 'Não empilhar', 'flag_fi' => 0, 'pessoa_id_pagador' => $this->client]);

        $booking = $this->create($data, 'e');

        $this->assertSame([1, 0, null], [(int) $booking->status_ams_africa, (int) $booking->status_ams_uae, $booking->pessoa_id_pagador]);
        $this->assertSame(1, (int) DB::table('cotacao_expo')->value('status'));
        $this->assertDatabaseHas('instrucao_especial_embarque', ['embarque_id' => $booking->getKey(), 'instrucao' => 'Não empilhar']);
        $this->assertContains('[Aviso] Reserva com BL DIRETO: SIM e Local Pagamento: CC - EXP-1', $this->sentSubjects()->all());
    }

    public function test_maritime_export_refuses_a_repeated_number_and_an_etd_after_the_validity(): void
    {
        $this->create($this->exportData(), 'e');

        foreach ([['reserva já foi utilizado', []], ['posterior à Data de Validade', ['booking' => 'EXP-2', 'saida_prevista' => '2027-02-01']]] as [$message, $overrides]) {
            try {
                $this->create($this->exportData($overrides), 'e');
                $this->fail('A reserva foi criada.');
            } catch (DomainException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->create($this->exportData(['consolidado' => 's']), 'e');
        $this->assertSame(2, Embarque::count());
    }

    public function test_air_booking_has_no_bl_expense_matrix_or_insurance_booking(): void
    {
        $booking = $this->create($this->bookingData(['booking' => 'AIMP1-26', 'de' => 'Ana', 'insurance' => 's', 'saida_prevista' => '2026-12-01']), 'i', 'a');

        $this->assertSame(['a', null], [$booking->tipo_embarque, $booking->etd_inicial]);
        // Como no legado: em branco é '', senão os filtros `loading_type <> 'f'` e `ce_mercante = ''` pulam a reserva.
        $this->assertSame(['', '', '', ''], [$booking->loading_type, $booking->house_bl, $booking->master_bl, $booking->ce_mercante]);
        $this->assertSame(0, DB::table('bl')->count());
        $this->assertSame(1, Embarque::count());
        $this->assertDatabaseHas('hist_embarque', ['embarque_id' => $booking->getKey()]);
    }

    public function test_booking_number_uses_the_seller_office_prefix_and_a_single_sequence(): void
    {
        $this->row('import_booking', ['sequencia' => 41]);

        $this->assertSame('IMP42-'.now()->format('y'), app(NextBookingNumber::class)($this->seller, 'm'));
        $this->assertSame('AIMP43-'.now()->format('y'), app(NextBookingNumber::class)($this->seller, 'a'));
    }

    public function test_import_quotation_fills_the_form_with_default_contacts_broker_and_containers(): void
    {
        $broker = $this->row('despachante', ['nome' => 'DESP']);
        DB::table('porto')->where('porto_id', $this->destination)->update(['despachante_id' => $broker]);
        $quotation = $this->row('cotacao_impo', ['pessoa_id' => $this->client, 'porto_origem_id' => $this->origin, 'porto_destino_id' => $this->destination, 'transit_time' => 30, 'free_time' => 14, 'armador_id' => 3, 'incoterm_id' => 1, 'validade_max' => '2027-01-01', 'fatura' => 'REF-7']);
        $this->row('cotacao_impo_container', ['cotacao_impo_id' => $quotation, 'tipo_container_id' => $this->equipment, 'quantidade' => 3, 'flag_imo' => 1]);

        $fields = app(QuotationPrefill::class)('i', 'm', $quotation);

        $this->assertSame(['REF-7', 14, '2027-01-01', $broker], [$fields['customer_ref'], $fields['free_time'], $fields['data_validade'], $fields['despachante_dmf_id']]);
        $this->assertSame([$this->contact], $fields['contacts.cliente']);
        $this->assertSame([['tipo_container_id' => $this->equipment, 'quantidade' => 3, 'quimico' => 1, 'carga_perigosa' => 1]], $fields['goods']);
    }

    private ?int $contactOfClient = null;

    private function create(array $data, string $operation = 'i', string $mode = 'm'): Embarque
    {
        return app(CreateBooking::class)($data, $operation, $mode, $this->author);
    }

    private function exportData(array $overrides = []): array
    {
        $this->contactOfClient ??= $this->contactOf($this->client, 3);

        return $this->bookingData([
            'booking' => 'EXP-1',
            'de' => 'Ana',
            'insurance' => null,
            'tipo_fechamento' => 'o',
            'armador_id' => 1,
            'via' => 'Direto',
            'free_time_detention' => 7,
            'data_validade' => '2027-01-31',
            'consolidado' => 'n',
            'porto_origem_id' => $this->destination,
            'porto_destino_id' => $this->origin,
            'contacts' => ['cliente' => [$this->contactOfClient]],
            ...$overrides,
        ]);
    }
}
