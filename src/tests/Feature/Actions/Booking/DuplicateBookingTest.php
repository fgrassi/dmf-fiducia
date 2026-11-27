<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\Creation\CreateBooking;
use App\Actions\Booking\DuplicateBooking;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BookingCreationFixture;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DuplicateBookingTest extends TestCase
{
    use BookingCreationFixture;
    use LegacySchema;

    /** O que o legado preenche numa reserva antiga e a cópia pode ou não levar. */
    private const FILLED = [
        'saida_efetiva' => '2026-02-01', 'chegada_efetiva' => '2026-03-01', 'etd_inicial' => '2026-02-01', 'eta_inicial' => '2026-03-01',
        'house_bl' => 'HBL', 'master_bl' => 'MBL', 'qtd_bl' => 2, 'data_validade' => '2026-12-31', 'ctr_seguro' => 1, 'insurance' => 's',
        'ce_mercante' => '123', 'cotacao_impo_id' => 7, 'flag_prioritario' => 1, 'chegada_desejada' => '2026-03-02',
        'cotacao_impo_aereo_id' => 9, 'flag_fi' => 1, 'pessoa_id_pagador' => 55, 'recebedor_freight_invoice' => 'a',
        'ctr_desp' => '2', 'ctr_pgto' => '2', 'ctr_pgto_outros' => '2', 'finalizado' => 'c', 'invoice' => 'INV9', 'via' => 'Santos',
        'data_topo' => '2026-01-10', 'prontidao' => '0000-00-00',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBookingFixture();
    }

    public function test_the_copy_takes_the_data_with_a_new_name_and_clears_what_belongs_to_the_old_shipment(): void
    {
        $original = $this->booking();
        $other = $this->row('usuario', ['nome' => 'Bia', 'credentials' => 'booking', 'status' => 1]);

        $copy = app(DuplicateBooking::class)($original, $this->author->find($other));

        $this->assertNotSame($original->getKey(), $copy->getKey());
        $this->assertSame(
            ['Cópia IMP1-26', 'INV9', 'Santos', '2026-01-10', 'n', $other],
            [$copy->booking, $copy->invoice, $copy->via, $copy->data_topo, $copy->finalizado, (int) $copy->intIdUser],
        );
        $this->assertSame(today()->toDateString(), $copy->dteCadastro);
        $this->assertSame([1, 1, 1], [(int) $copy->ctr_desp, (int) $copy->ctr_pgto, (int) $copy->ctr_pgto_outros]);

        foreach (['saida_efetiva', 'chegada_efetiva', 'etd_inicial', 'eta_inicial', 'house_bl', 'master_bl', 'qtd_bl', 'data_validade', 'ctr_seguro', 'insurance', 'ce_mercante', 'cotacao_impo_id', 'flag_prioritario', 'chegada_desejada', 'pessoa_id_pagador', 'recebedor_freight_invoice', 'prontidao'] as $column) {
            $this->assertNull($copy->{$column}, $column);
        }

        $this->assertSame([0, 9], [(int) $copy->flag_fi, (int) $copy->cotacao_impo_aereo_id]);
        $this->assertDatabaseHas('hist_embarque', ['embarque_id' => $copy->getKey(), 'noperacao' => 'I']);
        $this->assertSame('MBL', $original->fresh()->master_bl);
    }

    public function test_the_copy_takes_the_expenses_without_the_bl_link_and_leaves_goods_contacts_and_bls_behind(): void
    {
        $original = $this->booking();
        $this->row('fin_expenses_values', [
            'ref_embarque' => $original->getKey(), 'ref_expense' => 5, 'buying_rate' => 10, 'selling_rate' => 20, 'billing' => 'c',
            'ref_bl' => 3, 'ref_container' => 4, 'created_by' => 999, 'created_at' => '2020-01-01', 'updated_by' => 999, 'updated_at' => '2020-01-02',
        ]);

        $copy = app(DuplicateBooking::class)($original, $this->author);

        $this->assertDatabaseHas('fin_expenses_values', [
            'ref_embarque' => $copy->getKey(), 'ref_expense' => 5, 'buying_rate' => 10, 'selling_rate' => 20, 'billing' => 'c',
            'ref_bl' => null, 'ref_container' => null, 'created_by' => $this->author->getKey(), 'created_at' => today()->toDateString(), 'updated_by' => null,
        ]);
        $this->assertSame(2, DB::table('fin_expenses_values')->count());

        foreach (['mercadoria', 'embarque_contato_cliente', 'bl'] as $table) {
            $this->assertSame(0, DB::table($table)->where('embarque_id', $copy->getKey())->count(), $table);
        }
    }

    public function test_the_maritime_export_copy_takes_the_answered_freight_invoice_and_keeps_what_the_other_copy_clears(): void
    {
        $original = $this->booking(['impor_expor' => 'e', 'cotacao_expo_id' => 4]);

        $copy = app(DuplicateBooking::class)($original, $this->author, ['flag_fi' => 1, 'pessoa_id_pagador' => $this->client, 'recebedor_freight_invoice' => 'j']);

        $this->assertSame([1, $this->client, 'j'], [(int) $copy->flag_fi, (int) $copy->pessoa_id_pagador, $copy->recebedor_freight_invoice]);
        $this->assertSame(['123', 7, 1, null, null], [$copy->ce_mercante, (int) $copy->cotacao_impo_id, (int) $copy->flag_prioritario, $copy->cotacao_expo_id, $copy->data_validade]);

        $without = app(DuplicateBooking::class)($original, $this->author, ['flag_fi' => 0, 'pessoa_id_pagador' => $this->client]);

        $this->assertSame([0, null, null], [(int) $without->flag_fi, $without->pessoa_id_pagador, $without->recebedor_freight_invoice]);
    }

    #[DataProvider('freightRefusals')]
    public function test_the_maritime_export_copy_needs_the_freight_invoice_answers(array $answers, string $message): void
    {
        $original = $this->booking(['impor_expor' => 'e']);

        try {
            app(DuplicateBooking::class)($original, $this->author, $answers);
            $this->fail('A reserva foi duplicada.');
        } catch (DomainException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame(1, Embarque::count());
    }

    public static function freightRefusals(): array
    {
        return [
            'sem resposta' => [[], 'Freight Invoice'],
            'sem pagador' => [['flag_fi' => 1, 'recebedor_freight_invoice' => 'a'], 'Pagador'],
            'sem recebedor' => [['flag_fi' => 1, 'pessoa_id_pagador' => 1], 'Recebedor'],
        ];
    }

    public function test_the_copy_keeps_the_agent_only_while_it_is_one_and_flags_ams_by_destination_country(): void
    {
        $original = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'a']);
        $emirates = $this->row('porto', ['nome' => 'DUBAI', 'uncode' => 'AEDXB', 'ref_pais' => 5, 'tipo' => 'a']);
        DB::table('embarque')->where('embarque_id', $original->getKey())->update(['porto_destino_id' => $emirates, 'status_ams_africa' => 3]);

        $first = app(DuplicateBooking::class)($original, $this->author);
        DB::table('agente_pais')->update(['restricao' => 1]);
        $second = app(DuplicateBooking::class)($original, $this->author);

        $this->assertSame([$this->agent, null], [(int) $first->pessoa_id_representante, $second->pessoa_id_representante]);
        $this->assertSame([0, 1], [(int) $first->status_ams_africa, (int) $first->status_ams_uae]);
    }

    #[DataProvider('refusals')]
    public function test_refuses_a_booking_that_cannot_be_duplicated(array $columns, string $credentials): void
    {
        $original = $this->booking();
        $columns && DB::table('embarque')->where('embarque_id', $original->getKey())->update($columns);
        DB::table('usuario')->where('usuario_id', $this->author->getKey())->update(['credentials' => $credentials]);

        $this->expectExceptionMessage('não pode ser duplicada');

        app(DuplicateBooking::class)($original->fresh(), $this->author->fresh());
    }

    public static function refusals(): array
    {
        return ['seguro' => [['tipo_embarque' => 's'], 'booking'], 'outra operação' => [[], 'booking,export']];
    }

    /** Uma importação marítima com tudo o que o legado preenche ao longo do embarque. */
    private function booking(array $columns = []): Embarque
    {
        $booking = app(CreateBooking::class)($this->bookingData(['goods' => [['nome' => 'Peças', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0]]]), 'i', 'm', $this->author);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update([...self::FILLED, ...$columns]);

        return $booking->fresh();
    }
}
