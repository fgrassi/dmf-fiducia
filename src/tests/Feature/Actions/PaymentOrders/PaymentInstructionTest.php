<?php

namespace Tests\Feature\Actions\PaymentOrders;

use App\Actions\PaymentOrders\DeletePaymentInstruction;
use App\Actions\PaymentOrders\EditOrderCode;
use App\Actions\PaymentOrders\EditPaymentIof;
use App\Actions\PaymentOrders\GeneratePaymentInstruction;
use App\Actions\PaymentOrders\PaymentInstruction;
use App\Actions\PaymentOrders\PaymentInstructionPdf;
use App\Actions\PaymentOrders\SetOrderBank;
use App\Models\FinPaymentOrder;
use App\Models\InstrucaoPagamento;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PaymentInstructionTest extends TestCase
{
    use LegacySchema;

    public function test_generates_an_instruction_with_the_enabled_items_and_the_orders_iof(): void
    {
        $this->travelTo('2026-10-29 10:15:00');
        $order = $this->order(['valor_iof' => 12.5]);
        [$thc, $freight] = $this->expenses();
        $this->item($order, $thc, 550);
        $this->item($order, $thc, 672);
        $this->item($order, $freight, 250);
        DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $order->getKey(), 'ref_expense' => $freight, 'created_by' => 1, 'created_at' => '2026-10-01', 'status' => 0]);

        $instruction = app(GeneratePaymentInstruction::class)($order);

        $this->assertSame([12.5, '2026-10-29 10:15:00', $order->getKey()], [$instruction->valor_iof, $instruction->created_at->toDateTimeString(), (int) $instruction->fin_payment_order_id]);
        $this->assertSame([550.0, 672.0], $instruction->items()->orderBy('instrucao_pagamento_item_id')->pluck('valor')->all());
    }

    public function test_refuses_to_generate_when_every_expense_is_disabled(): void
    {
        $order = $this->order();
        [$thc] = $this->expenses();
        $this->item($order, $thc, 100);
        DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $order->getKey(), 'ref_expense' => $thc, 'created_by' => 1, 'created_at' => '2026-10-01', 'status' => 0]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A ordem não tem despesas ligadas para gerar a instrução.');

        app(GeneratePaymentInstruction::class)($order);
    }

    public function test_deleting_an_instruction_removes_its_items_only(): void
    {
        $order = $this->order();
        [$thc] = $this->expenses();
        $item = $this->item($order, $thc, 100);
        $instruction = app(GeneratePaymentInstruction::class)($order);

        app(DeletePaymentInstruction::class)($instruction);

        $this->assertModelMissing($instruction);
        $this->assertSame([0, 1], [DB::table('instrucao_pagamento_item')->count(), DB::table('fin_payment_order_items')->where('id', $item)->count()]);
    }

    public function test_edits_the_iof_of_the_order_and_of_an_instruction_and_rejects_a_negative_one(): void
    {
        $order = $this->order(['valor_iof' => 0]);
        [$thc] = $this->expenses();
        $this->item($order, $thc, 100);
        $instruction = app(GeneratePaymentInstruction::class)($order);

        app(EditPaymentIof::class)->order($order, 30);
        app(EditPaymentIof::class)->instruction($instruction, 7.25);

        $this->assertSame([30.0, 7.25], [(float) $order->fresh()->valor_iof, (float) $instruction->fresh()->valor_iof]);

        $this->expectException(DomainException::class);

        app(EditPaymentIof::class)->order($order, -1);
    }

    public function test_sets_the_identification_code_and_the_bank_of_the_order_with_boleto_as_zero(): void
    {
        $order = $this->order();
        $bank = DB::table('fin_armador_banks')->insertGetId(['ref_armador' => 7, 'ref_bank' => 1, 'created_by' => 1]);

        app(EditOrderCode::class)($order, 'ABC-1');
        app(SetOrderBank::class)($order, $bank);

        $this->assertSame(['ABC-1', $bank], [$order->fresh()->codigo_identificacao, (int) $order->fresh()->fin_armador_bank_id]);

        app(SetOrderBank::class)($order, 0);

        $this->assertNull($order->fresh()->fin_armador_bank_id);
    }

    public function test_rejects_a_bank_of_another_carrier_or_a_deactivated_one(): void
    {
        $order = $this->order();
        $foreign = DB::table('fin_armador_banks')->insertGetId(['ref_armador' => 99, 'ref_bank' => 1, 'created_by' => 1]);
        $deactivated = DB::table('fin_armador_banks')->insertGetId(['id' => 126, 'ref_armador' => 7, 'ref_bank' => 1, 'created_by' => 1]);

        foreach ([$foreign, $deactivated] as $bank) {
            try {
                app(SetOrderBank::class)($order, $bank);
                $this->fail('A conta deveria ser recusada.');
            } catch (DomainException $e) {
                $this->assertSame('A conta não pertence ao armador da ordem ou está desativada.', $e->getMessage());
            }
        }
    }

    public function test_collects_the_pdf_data_with_the_bank_or_via_boleto(): void
    {
        $this->travelTo('2026-10-29 10:15:00');
        [$order, $instruction] = $this->instruction();

        $data = app(PaymentInstruction::class)($instruction);

        $this->assertSame(
            ['PAGAMENTO AO ARMADOR: MAERSK', 'IP0001/2026', '29/10/2026', 'BKG10', 'EXPORTADORA (123)', 'MBL-1', 'HBL-1', 'Free Time Demurrage (dias)', '10', 'NAVIO D', '01/11/2026', '30/11/2026'],
            [$data['title'], $data['code'], $data['date'], $data['booking'], $data['client'], $data['mbl'], $data['hbl'], $data['free_time_label'], $data['free_time'], $data['ship'], $data['etd'], $data['eta']],
        );
        $this->assertSame([['expense' => 'THC/BL', 'buying' => 'USD 100,00', 'rate' => '5,50000000', 'value' => 'R$ 550,00']], $data['items']);
        $this->assertSame(['R$ 550,00', 'R$ 10,00', 'R$ 560,00', 'MAERSK - CNPJ: 111', null], [$data['subtotal'], $data['iof'], $data['total'], $data['carrier'], $data['bank']]);

        DB::table('fin_armador_banks')->insert(['id' => 5, 'ref_armador' => 7, 'ref_bank' => 1, 'bank_agency' => '0001', 'bank_account' => '123-4', 'id_code' => 'IDC', 'obs' => 'Obs', 'created_by' => 1]);
        DB::table('cmn_banks')->insert(['id' => 1, 'name' => 'Banco X', 'bank_number' => '001', 'created_by' => 1]);
        $order->forceFill(['fin_armador_bank_id' => 5])->save();

        $this->assertSame(['bank' => 'Banco X (001)', 'agency' => '0001', 'account' => '123-4', 'code' => 'IDC', 'notes' => 'Obs'], app(PaymentInstruction::class)($instruction->fresh())['bank']);

        $order->forceFill(['codigo_identificacao' => 'OVERRIDE'])->save();

        $this->assertSame('OVERRIDE', app(PaymentInstruction::class)($instruction->fresh())['bank']['code']);
    }

    public function test_renders_the_pdf(): void
    {
        [, $instruction] = $this->instruction();

        $this->assertStringStartsWith('%PDF', app(PaymentInstructionPdf::class)->render($instruction)->Output('', 'S'));
    }

    /** @return array{0: FinPaymentOrder, 1: InstrucaoPagamento} */
    private function instruction(): array
    {
        DB::table('armador')->insert(['armador_id' => 7, 'nome' => 'MAERSK', 'cnpj' => '111']);
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'EXPORTADORA', 'cnpj' => '123']);
        DB::table('navio')->insert(['navio_id' => 3, 'nome' => 'NAVIO D']);
        DB::table('embarque')->insert([
            'embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'i', 'cliente_id' => 1, 'master_bl' => 'MBL-1', 'house_bl' => 'HBL-1', 'free_time' => 10,
            'navio_id_destino' => 3, 'saida_prevista' => '2026-11-01', 'chegada_prevista' => '2026-11-30',
        ]);
        $order = $this->order(['valor_iof' => 10, 'mbl' => 'MBL-1']);
        [$thc] = $this->expenses();
        $this->item($order, $thc, 550);

        return [$order, app(GeneratePaymentInstruction::class)($order)];
    }

    /** @param  array<string, mixed>  $attributes */
    private function order(array $attributes = []): FinPaymentOrder
    {
        return FinPaymentOrder::find(DB::table('fin_payment_orders')->insertGetId([
            ...['ref_armador' => 7, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1, 'ref_embarque' => 10],
            ...$attributes,
        ]));
    }

    /** @return array{0: int, 1: int} ids de fin_expenses_values (THC e frete) */
    private function expenses(): array
    {
        DB::table('cmn_currencies')->insertOrIgnore(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses_unities')->insertOrIgnore(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insertOrIgnore([['id' => 10, 'name' => 'THC', 'ref_unity' => 1], ['id' => 11, 'name' => 'Frete', 'ref_unity' => 1]]);

        return [
            DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10, 'ref_currency_br' => 2]),
            DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 11, 'ref_currency_br' => 2]),
        ];
    }

    private function item(FinPaymentOrder $order, int $expense, float $value): int
    {
        return DB::table('fin_payment_order_items')->insertGetId([
            'ref_payment_order' => $order->getKey(), 'ref_expense' => $expense, 'unitary_value' => 100, 'ref_currency' => 2,
            'current_rate_value' => 5.5, 'item_value' => $value, 'created_at' => '2026-10-01', 'ref_bl' => 1,
        ]);
    }
}
