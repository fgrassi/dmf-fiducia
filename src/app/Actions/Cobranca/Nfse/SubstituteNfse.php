<?php

namespace App\Actions\Cobranca\Nfse;

use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\FinChargesNfse;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * NfseEmissaoService::substituir + substituirAposRecalculo (legado): emite a substituta (nova DPS com grupo subst)
 * pela API; a original vira "substituida" quando a substituta é autorizada (RefreshStatus).
 */
class SubstituteNfse
{
    /** Justificativa padrão quando o recálculo não informa uma (motivo 99). */
    public const RECALCULATION_REASON = 'Recálculo da instrução de cobrança com atualização de valores das despesas.';

    public function __construct(
        private NfseApi $api,
        private IssueNfse $issue,
        private ServiceTaker $taker,
    ) {}

    /** @return array{ok:bool, message:string} */
    public function __invoke(FinChargesNfse $original, array $data, ?int $userId): array
    {
        try {
            return ['ok' => true, 'message' => $this->substitute($original, $data, $userId)];
        } catch (DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * substituirAposRecalculo: substitui todas as autorizadas substituíveis da IC com os dados das originais e o valor atual.
     *
     * @return array{ok:bool, skipped:bool, error:?string}
     */
    public function afterRecalculation(FinCharges $charge, ?int $userId, string $reason = '99', string $justification = ''): array
    {
        $originals = FinChargesNfse::where('ref_charge', $charge->id)->orderByDesc('fin_charges_nfse_id')->get()->filter->canSubstitute();

        if ($originals->isEmpty()) {
            return ['ok' => true, 'skipped' => true, 'error' => null];
        }

        $reason = FinChargesNfse::substitutionReason($reason) ?: '99';
        $justification = CancelNfse::sanitize($justification) ?: ($reason === '99' ? self::RECALCULATION_REASON : '');
        $errors = [];

        foreach ($originals as $original) {
            $data = [...$this->issue->defaultsFromNfse($original), 'c_motivo' => $reason, 'x_motivo' => $justification];
            $current = $this->issue->currentValue($charge, (int) $original->config_nfse_operacao_id);

            if ($current !== null) {
                $data['valor_servicos'] = number_format($current, 2, '.', '');
            }

            $result = $this($original, $data, $userId);

            if (! $result['ok']) {
                $errors[] = $result['message'];
            }
        }

        return ['ok' => $errors === [], 'skipped' => false, 'error' => $errors ? implode('; ', $errors) : null];
    }

    /**
     * listarOutrasNotasDefasadas: outras autorizadas da IC cujo valor emitido não bate com o valor atual do grupo.
     *
     * @return list<array{id_externo:string, name:string, original:float, current:float}>
     */
    public function outdatedNotes(FinCharges $charge, FinChargesNfse $original): array
    {
        $values = $this->issue->groupValues($charge);

        return FinChargesNfse::where('ref_charge', $charge->id)->where('status', 'autorizada')->whereKeyNot($original->getKey())->get()
            ->filter(fn ($n) => isset($values[(int) $n->config_nfse_operacao_id]) && is_numeric($n->valor_servicos)
                && abs((float) $n->valor_servicos - $values[(int) $n->config_nfse_operacao_id]) >= 0.005)
            ->map(fn ($n) => [
                'id_externo' => (string) $n->id_externo,
                'name' => (string) (DB::table('config_nfse_operacao')->where('config_nfse_operacao_id', $n->config_nfse_operacao_id)->value('nome') ?: "operação #{$n->config_nfse_operacao_id}"),
                'original' => round((float) $n->valor_servicos, 2),
                'current' => $values[(int) $n->config_nfse_operacao_id],
            ])->values()->all();
    }

    /** @return string mensagem de sucesso */
    private function substitute(FinChargesNfse $original, array $data, ?int $userId): string
    {
        if (! $original->canSubstitute()) {
            throw new DomainException('Somente NFS-e autorizada, sem cancelamento ou substituição em andamento, pode ser substituída.');
        }

        $reason = FinChargesNfse::substitutionReason($data['c_motivo'] ?? '');
        $justification = CancelNfse::sanitize($data['x_motivo'] ?? '');

        match (true) {
            $reason === '' => throw new DomainException('Motivo de substituição inválido.'),
            $reason === '99' && $justification === '' => throw new DomainException('Para motivo "Outros", a justificativa é obrigatória.'),
            $justification !== '' && (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) => throw new DomainException('A justificativa deve ter entre 15 e 255 caracteres (atual: '.mb_strlen($justification).').'),
            ! NfseApi::configured() => throw new DomainException('Configuração da API NFS-e indisponível.'),
            default => null,
        };

        $charge = $original->charge;

        ServiceTaker::validateTypedContact($data);
        $taker = $this->taker->extract($data);
        $taker = $this->taker->completeContact(ServiceTaker::hasAddress($data) ? $this->taker->completeAddress($taker) : $taker);
        $this->taker->validate($taker);

        $operation = $this->issue->operation($charge, $data)
            ?? ($original->config_nfse_operacao_id ? IssueNfse::operationById($original->config_nfse_operacao_id) : null)
            ?? throw new DomainException('Selecione o tipo de operação NFSe antes de substituir.');
        $value = $this->issue->formValue($charge, (int) $operation->config_nfse_operacao_id, $original, $data)
            ?? throw new DomainException('Informe o valor dos serviços da NFS-e substituta.');
        $competence = filled($data['competencia'] ?? null) ? IssueNfse::competence($data['competencia']) : null;

        $externalId = $this->issue->nextExternalId($charge);
        $full = $this->issue->payload($charge, $operation, $taker, trim((string) ($data['descricao_servico'] ?? '')) ?: (string) $operation->descricao_servico, $externalId, $value, $competence);
        $payload = ['id_externo' => $externalId, 'c_motivo' => $reason]
            + array_intersect_key($full, array_flip(['competencia', 'tomador', 'servico', 'valores', 'ibs_cbs', 'informacoes_complementares', 'webhook_url']))
            + array_filter(['x_motivo' => $justification]);

        $record = (new FinChargesNfse)->forceFill([
            'ref_charge' => $charge->id,
            'config_nfse_operacao_id' => $operation->config_nfse_operacao_id,
            'nfse_substituida_id' => $original->getKey(),
            'id_externo' => $externalId,
            'payload_json' => json_encode($full),
            'tomador_json' => json_encode($taker),
            'valor_servicos' => IssueNfse::serviceValue($charge, $value),
            'status' => 'enfileirada',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->api->substitute($original->nfse_uuid, $payload);
        $this->issue->applyResponse($record, $response, 'Falha ao solicitar substituição da NFS-e.');

        if (! $response['ok']) {
            throw new DomainException((string) (json_decode((string) $record->erros_json, true)[0]['descricao'] ?? 'Falha ao solicitar substituição da NFS-e.'));
        }

        // aplicarVinculoSubstituicao: já autorizada (idempotente) → a original vira "substituida"
        if ($record->status === 'autorizada' && $original->refresh()->status === 'autorizada') {
            $original->forceFill(['status' => 'substituida', 'erros_json' => null, 'updated_at' => now()])->save();
        }

        $message = $response['http_code'] === 200
            ? "Substituição já existente na API. Status: {$record->status}."
            : 'Substituição enfileirada. A nota original será cancelada automaticamente quando a substituta for autorizada.';
        $allow = json_decode((string) $charge->emails_envio_json, true);

        FinChargesLog::forceCreate([
            'ref_charge' => $charge->id,
            'acao' => 'substituicao_nfse',
            'historico' => "Substituição NFS-e solicitada (original: {$original->id_externo}; nova: {$record->id_externo}; motivo: "
                .FinChargesNfse::SUBSTITUTION_REASONS[$reason]
                .($justification !== '' ? "; justificativa: {$justification}" : '')
                ."; valor: {$record->valor_servicos}). {$message}"
                .FinCharges::recipientsLogSummary(is_array($allow) ? $allow : null),
            'created_at' => now(),
            'created_by' => $userId,
        ]);

        return $message;
    }
}
