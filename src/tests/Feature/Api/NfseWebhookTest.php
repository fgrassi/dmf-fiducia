<?php

namespace Tests\Feature\Api;

use App\Models\ConfigNfse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NfseWebhookTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('fin_charges')->insert(['id' => 1, 'ref_type' => 1, 'ref_booking' => 1, 'status' => 0, 'created_at' => '2026-10-27']);
        DB::table('fin_charges_nfse')->insert([
            ['fin_charges_nfse_id' => 1, 'ref_charge' => 1, 'id_externo' => 'BKG1', 'nfse_uuid' => 'uuid-1', 'status' => 'enfileirada'],
            ['fin_charges_nfse_id' => 2, 'ref_charge' => 1, 'id_externo' => 'BKG1', 'nfse_uuid' => null, 'status' => 'enfileirada'],
        ]);
    }

    /** POST assinado com o segredo configurado (o endpoint recusa sem segredo). */
    private function signedPost(array $payload, string $secret = 's3cret'): TestResponse
    {
        ConfigNfse::current()->update(['webhook_secret' => $secret]);
        $body = json_encode($payload);

        return $this->call('POST', '/api/nfse/webhook', server: ['HTTP_X_NFSE_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret)], content: $body);
    }

    private function payload(array $data = [], array $extra = []): array
    {
        return ['event' => 'nfse.status.updated', 'data' => $data + ['id' => 'uuid-1', 'id_externo' => 'BKG1', 'status' => 'autorizada', 'chave_acesso' => 'CHAVE'], ...$extra];
    }

    public function test_applies_final_status_by_uuid(): void
    {
        $this->signedPost($this->payload())
            ->assertOk()
            ->assertJson(['ok' => true, 'id_externo' => 'BKG1', 'status' => 'autorizada']);

        $row = DB::table('fin_charges_nfse')->where('fin_charges_nfse_id', 1)->first();
        $this->assertSame(['autorizada', 'CHAVE'], [$row->status, $row->chave_acesso]);
        $this->assertStringContainsString('uuid-1', $row->response_json);
    }

    public function test_falls_back_to_latest_external_id_and_fills_the_uuid(): void
    {
        $this->signedPost($this->payload(['id' => 'uuid-9', 'status' => 'rejeitada', 'erros' => [['codigo' => 'E1']]]))->assertOk();

        $row = DB::table('fin_charges_nfse')->where('fin_charges_nfse_id', 2)->first();
        $this->assertSame(['uuid-9', 'rejeitada'], [$row->nfse_uuid, $row->status]);
        $this->assertStringContainsString('E1', $row->erros_json);
    }

    public function test_marks_the_original_as_replaced_when_the_substitute_is_authorized(): void
    {
        DB::table('fin_charges_nfse')->where('fin_charges_nfse_id', 1)->update(['status' => 'autorizada']);
        DB::table('fin_charges_nfse')->where('fin_charges_nfse_id', 2)->update(['nfse_uuid' => 'uuid-2', 'nfse_substituida_id' => 1]);

        $this->signedPost($this->payload(['id' => 'uuid-2']))->assertOk();

        $this->assertSame('substituida', DB::table('fin_charges_nfse')->where('fin_charges_nfse_id', 1)->value('status'));
    }

    public function test_rejects_bad_payloads(): void
    {
        $this->signedPost($this->payload(['id' => 'x', 'id_externo' => 'nope']))->assertNotFound();
        $this->signedPost($this->payload(['status' => 'enfileirada']))->assertStatus(422);
        $this->signedPost(['data' => []])->assertStatus(422);
        $this->signedPost($this->payload(extra: ['event' => 'other']))->assertStatus(400);
        ConfigNfse::current()->update(['webhook_secret' => 's3cret']);
        $this->call('POST', '/api/nfse/webhook', server: ['HTTP_X_NFSE_SIGNATURE' => 'sha256='.hash_hmac('sha256', 'not json', 's3cret')], content: 'not json')->assertStatus(400);
        $this->get('/api/nfse/webhook')->assertStatus(405);
    }

    public function test_refuses_when_no_secret_is_configured(): void
    {
        $this->postJson('/api/nfse/webhook', $this->payload())->assertStatus(503);
        $this->assertSame('enfileirada', DB::table('fin_charges_nfse')->where('fin_charges_nfse_id', 1)->value('status'));
    }

    public function test_requires_the_hmac_signature_when_a_secret_is_configured(): void
    {
        ConfigNfse::current()->update(['webhook_secret' => 's3cret']);
        $body = json_encode($this->payload());

        $this->call('POST', '/api/nfse/webhook', content: $body)->assertUnauthorized();
        $this->call('POST', '/api/nfse/webhook', server: ['HTTP_X_NFSE_SIGNATURE' => 'sha256=bad'], content: $body)->assertUnauthorized();
        $this->call('POST', '/api/nfse/webhook', server: ['HTTP_X_NFSE_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 's3cret')], content: $body)->assertOk();
    }
}
