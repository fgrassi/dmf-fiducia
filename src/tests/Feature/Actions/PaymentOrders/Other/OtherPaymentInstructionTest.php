<?php

namespace Tests\Feature\Actions\PaymentOrders\Other;

use App\Actions\PaymentOrders\EditPaymentIof;
use App\Actions\PaymentOrders\Other\DeleteOtherPaymentInstruction;
use App\Actions\PaymentOrders\Other\OtherPaymentInstruction;
use App\Actions\PaymentOrders\Other\OtherPaymentInstructionPdf;
use App\Models\InstrucaoPagamentoOutros;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class OtherPaymentInstructionTest extends TestCase
{
    use LegacySchema;

    private InstrucaoPagamentoOutros $instruction;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']]);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert(['id' => 10, 'name' => 'Despacho', 'ref_unity' => 1]);
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'ACME', 'cnpj' => '11.222.333/0001-44']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'master_bl' => 'MBL1', 'house_bl' => 'HBL1', 'cliente_id' => 1, 'impor_expor' => 'e', 'free_time_detention' => 14, 'saida_prevista' => '2026-11-01']);
        $order = DB::table('ordem_pagamento')->insertGetId(['ref_embarque' => 10, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1]);
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => 10, 'ref_currency_br' => 2]);
        $item = DB::table('ordem_pagamento_item')->insertGetId(['ordem_pagamento_id' => $order, 'ref_expense' => $expense, 'unitary_value' => 100, 'ref_currency' => 2, 'current_rate_value' => 5.5, 'item_value' => 550, 'created_at' => '2026-10-01', 'status' => 1, 'indice' => 1]);
        $this->instruction = InstrucaoPagamentoOutros::find(DB::table('instrucao_pagamento_outros')->insertGetId([
            'ordem_pagamento_id' => $order, 'created_at' => '2026-10-02 09:00:00', 'valor_iof' => 10, 'recebedor' => 'Despachante Sul', 'cnpj_recebedor' => '99', 'dados_bancarios' => 'Banco 1 Ag 2 Cc 3',
        ]));
        DB::table('instrucao_pagamento_item_outros')->insert(['instrucao_pagamento_outros_id' => $this->instruction->getKey(), 'ordem_pagamento_item_id' => $item, 'valor' => 550]);
    }

    public function test_builds_the_pdf_data_with_the_reservation_the_items_and_the_bank_text(): void
    {
        $data = app(OtherPaymentInstruction::class)($this->instruction);

        $this->assertSame(['ORDEM PAGAMENTO EXTRA', 'IP0001/2026', '02/10/2026', 'BKG10', 'ACME (11.222.333/0001-44)', 'Free Time Detention (dias)', '14', '01/11/2026'],
            [$data['title'], $data['code'], $data['date'], $data['booking'], $data['client'], $data['free_time_label'], $data['free_time'], $data['etd']]);
        $this->assertSame([['expense' => 'Despacho/BL', 'buying' => 'USD 100,00', 'rate' => '5,50000000', 'value' => 'R$ 550,00']], $data['items']);
        $this->assertSame(['R$ 550,00', 'R$ 10,00', 'R$ 560,00', 'Banco 1 Ag 2 Cc 3'], [$data['subtotal'], $data['iof'], $data['total'], $data['bank_text']]);
        $this->assertStringStartsWith('%PDF', app(OtherPaymentInstructionPdf::class)->render($this->instruction)->Output('', 'S'));
    }

    public function test_edits_the_iof_and_deletes_the_instruction_with_its_items(): void
    {
        app(EditPaymentIof::class)->instruction($this->instruction, 20);

        $this->assertEquals(20, $this->instruction->refresh()->valor_iof);

        app(DeleteOtherPaymentInstruction::class)($this->instruction);

        $this->assertModelMissing($this->instruction);
        $this->assertSame(0, DB::table('instrucao_pagamento_item_outros')->count());
        $this->assertSame(1, DB::table('ordem_pagamento_item')->count());
    }
}
