<?php

namespace Tests\Feature\Actions\Cobranca\Boleto;

use App\Actions\Cobranca\Boleto\IssueBoleto;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\BuildsBoletos;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SicrediWebhookTest extends TestCase
{
    use BuildsBoletos, BuildsCharges, LegacySchema;

    private FinCharges $charge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sicredi();
        $this->charge = $this->boletoCharge();
        app(IssueBoleto::class)($this->charge, 1);
        // sem NFS-e na baixa: o cliente não tem prazo negociado
        DB::table('fin_clients_nfse_regra')->insert(['ref_client' => 20, 'tomador' => 'cliente', 'justificativa' => 'x', 'bloquear_emissao' => 1]);
    }

    private function event(array $payload = [], string $query = '?token=segredo'): TestResponse
    {
        return $this->postJson("/api/sicredi/webhook{$query}", $payload + [
            'idEventoWebhook' => 'evt-1', 'nossoNumero' => '211001234', 'movimento' => 'LIQUIDACAO_PIX',
            'valorLiquidacao' => '1010,50', 'valorJuros' => 15.5, 'dataEvento' => [2026, 11, 25, 10, 0],
        ]);
    }

    public function test_liquidation_settles_the_open_charge_once(): void
    {
        $this->event()->assertOk()->assertJson(['outcome' => 'baixado', 'duplicado' => false, 'ref_charge' => $this->charge->id]);
        $this->event()->assertOk()->assertJson(['outcome' => 'duplicado', 'duplicado' => true]);

        $this->assertDatabaseHas('fin_charges', ['id' => $this->charge->id, 'status' => 2, 'received_value' => 1010.5, 'interest' => 15.5, 'discount' => 10, 'payment_confirmation' => '2026-11-25']);
        $this->assertDatabaseHas('fin_charges_boleto_sicredi', ['status' => 'liquidado']);
        $this->assertSame(1, DB::table('fin_payment_boleto')->where('ref_charge', $this->charge->id)->count());
        $this->assertSame(1, DB::table('fin_charges_log')->where('acao', 'baixa_boleto')->count());
        $this->assertCount(1, $this->sentSubjects());
    }

    public function test_reversal_is_recorded_without_reopening_the_charge(): void
    {
        $this->event();

        $this->event(['idEventoWebhook' => 'evt-2', 'movimento' => 'ESTORNO_LIQUIDACAO_REDE'])->assertOk()->assertJson(['outcome' => 'estornado']);

        $this->assertDatabaseHas('fin_charges', ['id' => $this->charge->id, 'status' => 2]);
        $this->assertDatabaseHas('fin_charges_boleto_sicredi', ['status' => 'estornado']);
    }

    public function test_unknown_boleto_is_acknowledged_and_ignored(): void
    {
        $this->event(['nossoNumero' => '999'])->assertOk()->assertJson(['outcome' => 'not_found']);

        $this->assertDatabaseHas('fin_sicredi_webhook_evento', ['status_processamento' => 'ignorado']);
        $this->assertDatabaseHas('fin_charges', ['id' => $this->charge->id, 'status' => 0]);
    }

    #[TestWith(['', 401])]
    #[TestWith(['?token=errado', 401])]
    public function test_refuses_calls_without_the_secret(string $query, int $status): void
    {
        $this->event(query: $query)->assertStatus($status);

        $this->assertDatabaseHas('fin_charges', ['id' => $this->charge->id, 'status' => 0]);
    }

    public function test_refuses_everything_while_no_secret_is_configured(): void
    {
        config(['services.sicredi.webhook_token' => '']);

        $this->event(query: '?token=')->assertStatus(503);

        $this->assertDatabaseCount('fin_sicredi_webhook_evento', 0);
    }

    public function test_incomplete_payload_is_rejected(): void
    {
        $this->event(['movimento' => ''])->assertStatus(422);
    }
}
