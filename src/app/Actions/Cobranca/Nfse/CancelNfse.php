<?php

namespace App\Actions\Cobranca\Nfse;

use App\Models\FinChargesLog;
use App\Models\FinChargesNfse;
use DomainException;

/**
 * cancelarNfse + NfseEmissaoService::cancelar (legado): solicita o cancelamento na API (assíncrono)
 * e registra no histórico da IC. Notas de R$ 50.000 ou mais não podem ser canceladas.
 */
class CancelNfse
{
    public const REASONS = ['1' => '1 — Erro na emissão', '2' => '2 — Serviço não prestado', '9' => '9 — Outros'];

    public function __construct(
        private NfseApi $api,
        private RefreshStatus $refresh,
    ) {}

    /** @return string mensagem de sucesso */
    public function __invoke(FinChargesNfse $nfse, string $reason, ?string $justification, ?int $userId): string
    {
        if ($nfse->exceedsCancelLimit()) {
            throw new DomainException('Notas com valor igual ou superior a R$ '.number_format(FinChargesNfse::CANCEL_LIMIT, 2, ',', '.').' não podem ser canceladas.');
        }

        if ($nfse->hasPendingCancellation()) {
            ($this->refresh)($nfse);
        }

        if (! $nfse->canCancel()) {
            throw new DomainException($nfse->hasPendingCancellation()
                ? 'Cancelamento ainda em andamento na API. Clique no relógio/atualizar status e tente de novo.'
                : 'Somente NFS-e autorizada com chave de acesso pode ser cancelada.');
        }

        $reason = trim($reason);
        $justification = self::sanitize($justification);

        if (! isset(self::REASONS[$reason])) {
            throw new DomainException('Motivo inválido. Use 1 (erro na emissão), 2 (serviço não prestado) ou 9 (outros).');
        }

        if ($reason === '9' && $justification === '') {
            throw new DomainException('Para motivo "Outros", a justificativa é obrigatória.');
        }

        if ($justification !== '' && (mb_strlen($justification) < 15 || mb_strlen($justification) > 255)) {
            throw new DomainException('A justificativa deve ter entre 15 e 255 caracteres (atual: '.mb_strlen($justification).').');
        }

        $response = $this->api->cancel($nfse->nfse_uuid, ['c_motivo' => $reason, ...($justification !== '' ? ['x_motivo' => $justification] : [])]);
        $nfse->forceFill(['response_json' => $response['raw'], 'updated_at' => now()]);

        if (! $response['ok']) {
            $body = is_array($response['body']) ? $response['body'] : [];

            if (! empty($body['errors']) && is_array($body['errors'])) {
                $nfse->erros_json = json_encode($body['errors']);
            }

            $nfse->save();

            throw new DomainException(($body['message'] ?? null) ?: ($response['error'] ?: 'Falha ao solicitar cancelamento da NFS-e.'));
        }

        $data = is_array($response['body']) ? ($response['body']['data'] ?? $response['body']) : null;

        if (is_array($data)) {
            if (! empty($data['status'])) {
                $nfse->status = $data['status'];
            }

            $nfse->applyApiData($data);

            if (($data['status'] ?? null) === 'cancelada') {
                $nfse->erros_json = null;
            }
        }

        $nfse->save();

        $message = $response['http_code'] === 200 && ($data['status'] ?? null) === 'cancelada'
            ? 'NFS-e já estava cancelada.'
            : 'Cancelamento enfileirado. Atualize o status em instantes para confirmar.';

        FinChargesLog::forceCreate([
            'ref_charge' => $nfse->ref_charge,
            'acao' => 'alteracao',
            'historico' => "Cancelamento NFS-e solicitado (id externo: {$nfse->id_externo}; motivo: {$reason}".($justification !== '' ? "; {$justification}" : '')."). {$message}",
            'created_at' => now(),
            'created_by' => $userId,
        ]);

        return $message;
    }

    /** FinChargesNfse::sanitizarXMotivo: o XSD nacional não aceita CR/LF/TAB. */
    public static function sanitize(?string $text): string
    {
        return trim(preg_replace('/ +/', ' ', preg_replace('/[\r\n\t]+/', ' ', (string) $text)));
    }
}
