<?php

namespace Tests\Feature\Actions\ControlePa;

use App\Actions\ControlePa\ControlePaNotices;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ControlePaNoticesTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pessoa')->insert([
            ['pessoa_id' => 1, 'nome' => 'Importadora Sul'],
            ['pessoa_id' => 2, 'nome' => 'Exportadora Norte'],
            ['pessoa_id' => 3, 'nome' => 'Agente Hamburgo'],
        ]);
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'Maersk']);
    }

    public function test_ready_to_send_to_agent_lists_confirmed_bookings_outside_boxes(): void
    {
        $this->booking(10, 'BKG10', confirmed: true);
        $this->booking(11, 'BKG11', confirmed: true);
        $this->booking(12, 'BKG12', confirmed: false);
        $this->booking(13, 'BKG13', confirmed: true);
        $this->controls([
            ['embarque_id' => 10, 'flag_fi_dmf' => 0, 'flag_fi_armador' => 0],
            ['embarque_id' => 11, 'flag_fi_dmf' => 0, 'flag_fi_armador' => 0],
            ['embarque_id' => 12, 'flag_fi_dmf' => 0, 'flag_fi_armador' => 0],
            ['embarque_id' => 13, 'flag_fi_dmf' => 1, 'flag_fi_armador' => 0, 'data_envio_fi_agente' => '2026-11-02'],
        ]);
        DB::table('controle_caixa_embarque')->insert(['embarque_id' => 11, 'controle_caixa_id' => 1]);

        $sent = app(ControlePaNotices::class)->readyToSendToAgent();

        $this->assertSame(1, $sent);
        $this->assertSame(['PREPAID ABROAD - Freight Invoices disponíveis para envio ao agente'], $this->sentSubjects()->all());
        $body = $this->sentHtml();
        $this->assertStringContainsString('<td>BKG10</td><td>05/12/2026</td><td>20/01/2027</td><td>Importadora Sul</td><td>Exportadora Norte</td><td>Agente Hamburgo</td><td>Não enviado</td>', $body);
        $this->assertStringNotContainsString('BKG11', $body);
        $this->assertStringNotContainsString('BKG12', $body);
        $this->assertStringNotContainsString('BKG13', $body);
    }

    public function test_ready_to_send_to_carrier_lists_bookings_sent_to_agent_without_carrier_invoice(): void
    {
        $this->booking(10, 'BKG10');
        $this->booking(11, 'BKG11');
        $this->controls([
            ['embarque_id' => 10, 'flag_fi_dmf' => 1, 'flag_fi_armador' => 0, 'data_envio_fi_agente' => '2026-11-02'],
            ['embarque_id' => 11, 'flag_fi_dmf' => 1, 'flag_fi_armador' => 1, 'data_envio_fi_agente' => '2026-11-02'],
        ]);

        $sent = app(ControlePaNotices::class)->readyToSendToCarrier();

        $this->assertSame(1, $sent);
        $this->assertSame(['PREPAID ABROAD - Freight Invoices disponíveis para envio ao Armador'], $this->sentSubjects()->all());
        $this->assertStringContainsString('<td>BKG10</td>', $this->sentHtml());
        $this->assertStringContainsString('<td>Maersk</td><td>Não enviado</td>', $this->sentHtml());
        $this->assertStringNotContainsString('BKG11', $this->sentHtml());
    }

    public function test_pending_payment_lists_bookings_with_carrier_invoice_and_no_payment_date(): void
    {
        $this->booking(10, 'BKG10');
        $this->booking(11, 'BKG11');
        $this->controls([
            ['embarque_id' => 10, 'flag_fi_dmf' => 1, 'flag_fi_armador' => 1, 'data_envio_fi_agente' => '2026-11-02'],
            ['embarque_id' => 11, 'flag_fi_dmf' => 1, 'flag_fi_armador' => 1, 'data_envio_fi_agente' => '2026-11-02', 'data_pgto_armador' => '2026-11-05'],
        ]);

        $sent = app(ControlePaNotices::class)->pendingPayment();

        $this->assertSame(1, $sent);
        $this->assertSame(['PREPAID ABROAD - Pendências de Pagamento de Frete'], $this->sentSubjects()->all());
        $this->assertStringContainsString('BKG10', $this->sentHtml());
        $this->assertStringNotContainsString('BKG11', $this->sentHtml());
    }

    public function test_pending_receipt_lists_paid_bookings_without_carrier_receipt(): void
    {
        $this->booking(10, 'BKG10');
        $this->booking(11, 'BKG11');
        $this->controls([
            ['embarque_id' => 10, 'flag_fi_dmf' => 1, 'flag_fi_armador' => 1, 'data_envio_fi_agente' => '2026-11-02', 'data_pgto_armador' => '2026-11-05'],
            ['embarque_id' => 11, 'flag_fi_dmf' => 1, 'flag_fi_armador' => 1, 'data_envio_fi_agente' => '2026-11-02', 'data_pgto_armador' => '2026-11-05', 'data_recibo_armador' => '2026-11-09'],
        ]);

        $sent = app(ControlePaNotices::class)->pendingReceipt();

        $this->assertSame(1, $sent);
        $this->assertSame(['PREPAID ABROAD - Pendências de Confirmação da Liberação'], $this->sentSubjects()->all());
        $this->assertStringContainsString('<td>BKG10</td>', $this->sentHtml());
        $this->assertStringContainsString('<td>05/11/2026</td>', $this->sentHtml());
        $this->assertStringNotContainsString('BKG11', $this->sentHtml());
    }

    public function test_confirmed_without_invoice_lists_bookings_with_freight_expense_and_no_control(): void
    {
        $this->booking(10, 'BKG10', confirmed: true);
        $this->booking(11, 'BKG11', confirmed: true);
        $this->booking(12, 'BKG12', confirmed: true, status: 'c');
        $this->booking(13, 'BKG13', confirmed: true);
        $this->booking(14, 'BKG14', confirmed: false);
        foreach ([10, 11, 12, 14] as $id) {
            DB::table('fin_expenses_values')->insert(['ref_embarque' => $id, 'billing' => 'f']);
        }
        DB::table('fin_expenses_values')->insert(['ref_embarque' => 13, 'billing' => 'p']);
        DB::table('controle_pa')->insert(['embarque_id' => 11, 'flag_fi_dmf' => 0, 'flag_fi_armador' => 0]);

        $sent = app(ControlePaNotices::class)->confirmedWithoutInvoice();

        $this->assertSame(1, $sent);
        $this->assertSame(['PREPAID ABROAD - Embarques Confirmados'], $this->sentSubjects()->all());
        $this->assertStringContainsString('<td>BKG10</td><td>05/12/2026</td>', $this->sentHtml());
        foreach (['BKG11', 'BKG12', 'BKG13', 'BKG14'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $this->sentHtml());
        }
    }

    public function test_sends_nothing_when_no_booking_is_stuck(): void
    {
        $sent = app(ControlePaNotices::class)->readyToSendToAgent()
            + app(ControlePaNotices::class)->readyToSendToCarrier()
            + app(ControlePaNotices::class)->pendingReceipt()
            + app(ControlePaNotices::class)->pendingPayment()
            + app(ControlePaNotices::class)->confirmedWithoutInvoice();

        $this->assertSame(0, $sent);
        $this->assertSame([], $this->sentSubjects()->all());
    }

    private function booking(int $id, string $booking, bool $confirmed = false, string $status = 'a'): void
    {
        DB::table('embarque')->insert([
            'embarque_id' => $id,
            'booking' => $booking,
            'finalizado' => $status,
            'pessoa_id_importador' => 1,
            'pessoa_id_cliente' => 2,
            'pessoa_id_representante' => 3,
            'armador_id' => 1,
            'saida_prevista' => '2026-12-05',
            'saida_efetiva' => '2026-12-05',
            'chegada_prevista' => '2027-01-20',
        ]);

        if ($confirmed) {
            DB::table('departure_confirmation')->insert(['ref_embarque' => $id, 'created_by' => 1]);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function controls(array $rows): void
    {
        foreach ($rows as $row) {
            DB::table('controle_pa')->insert($row);
        }
    }
}
