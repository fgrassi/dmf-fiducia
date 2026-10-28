<?php

namespace Tests\Feature\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Nfse\CancelNfse;
use App\Actions\Cobranca\Nfse\NfseApi;
use App\Actions\Cobranca\Nfse\RefreshStatus;
use App\Models\FinChargesNfse;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CancelNfseTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    private $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->mock(NfseApi::class);
        $this->mock(RefreshStatus::class);
    }

    private function nfse(array $attributes = []): FinChargesNfse
    {
        $charge = $this->charge();
        $id = DB::table('fin_charges_nfse')->insertGetId([
            'ref_charge' => $charge->id, 'id_externo' => 'BKG10', 'nfse_uuid' => 'uuid-1', 'status' => 'autorizada',
            'chave_acesso' => 'CHAVE', 'valor_servicos' => 1000, ...$attributes,
        ]);

        return FinChargesNfse::find($id);
    }

    private function cancel(FinChargesNfse $nfse, string $reason = '1', ?string $justification = null)
    {
        return app(CancelNfse::class)($nfse, $reason, $justification, 5);
    }

    private function apiResult(int $code, array $body, bool $ok = true): array
    {
        return ['ok' => $ok, 'http_code' => $code, 'body' => $body, 'raw' => json_encode($body), 'error' => null];
    }

    public function test_queues_the_cancellation_and_logs_it(): void
    {
        $nfse = $this->nfse();
        $this->api->shouldReceive('cancel')->once()->with('uuid-1', ['c_motivo' => '9', 'x_motivo' => 'emitida para o cliente errado'])->andReturn($this->apiResult(202, ['data' => ['status' => 'autorizada']]));

        $message = $this->cancel($nfse, '9', "emitida para\no cliente errado");

        $this->assertSame('Cancelamento enfileirado. Atualize o status em instantes para confirmar.', $message);
        $log = DB::table('fin_charges_log')->first();
        $this->assertSame(['alteracao', 5], [$log->acao, (int) $log->created_by]);
        $this->assertStringContainsString('motivo: 9; emitida para o cliente errado', $log->historico);
    }

    public function test_an_already_cancelled_note_updates_the_status(): void
    {
        $nfse = $this->nfse(['erros_json' => '[{"codigo":"X"}]']);
        $this->api->shouldReceive('cancel')->once()->andReturn($this->apiResult(200, ['data' => ['status' => 'cancelada']]));

        $this->assertSame('NFS-e já estava cancelada.', $this->cancel($nfse));

        $nfse->refresh();
        $this->assertSame(['cancelada', null], [$nfse->status, $nfse->erros_json]);
    }

    public function test_api_failure_keeps_the_note_and_surfaces_the_api_message(): void
    {
        $nfse = $this->nfse();
        $this->api->shouldReceive('cancel')->once()->andReturn($this->apiResult(422, ['message' => 'Prazo expirado', 'errors' => [['codigo' => 'E9']]], ok: false));

        try {
            $this->cancel($nfse);
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('Prazo expirado', $e->getMessage());
        }

        $nfse->refresh();
        $this->assertSame('autorizada', $nfse->status);
        $this->assertStringContainsString('E9', $nfse->erros_json);
        $this->assertSame(0, DB::table('fin_charges_log')->count());
    }

    public function test_refuses_before_calling_the_api(): void
    {
        $this->api->shouldNotReceive('cancel');

        $this->assertRefused($this->nfse(['valor_servicos' => 50000]), '1', null, 'Notas com valor igual ou superior a R$ 50.000,00 não podem ser canceladas.');
        $this->assertRefused($this->nfse(['status' => 'rejeitada']), '1', null, 'Somente NFS-e autorizada com chave de acesso pode ser cancelada.');
        $this->assertRefused($this->nfse(['chave_acesso' => null]), '1', null, 'Somente NFS-e autorizada com chave de acesso pode ser cancelada.');
        $this->assertRefused($this->nfse(), '7', null, 'Motivo inválido. Use 1 (erro na emissão), 2 (serviço não prestado) ou 9 (outros).');
        $this->assertRefused($this->nfse(), '9', ' ', 'Para motivo "Outros", a justificativa é obrigatória.');
        $this->assertRefused($this->nfse(), '1', 'curta', 'A justificativa deve ter entre 15 e 255 caracteres (atual: 5).');
    }

    private function assertRefused(FinChargesNfse $nfse, string $reason, ?string $justification, string $message): void
    {
        try {
            $this->cancel($nfse, $reason, $justification);
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
