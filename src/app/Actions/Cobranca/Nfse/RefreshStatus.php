<?php

namespace App\Actions\Cobranca\Nfse;

use App\Models\FinChargesNfse;

/**
 * NfseEmissaoService::atualizarStatus (legado): consulta a nota na API, grava status/chave/DANFSe/erros,
 * marca a original como "substituida" quando a substituta é autorizada e envia o e-mail de autorização pendente.
 */
class RefreshStatus
{
    public function __construct(
        private NfseApi $api,
        private SendAuthorizationEmail $email,
    ) {}

    public function __invoke(FinChargesNfse $nfse): FinChargesNfse
    {
        if (blank($nfse->nfse_uuid)) {
            return $nfse;
        }

        $response = $this->api->show($nfse->nfse_uuid);
        $nfse->forceFill(['response_json' => $response['raw'], 'updated_at' => now()]);

        if (! $response['ok']) {
            // mantém o status; só anota o erro (nota autorizada não ganha erro de consulta)
            if ($nfse->status !== 'autorizada') {
                $nfse->erros_json = json_encode([['codigo' => "HTTP_{$response['http_code']}", 'descricao' => $response['error'] ?: 'Falha ao consultar NFSe']]);
            }

            $nfse->save();

            return $nfse;
        }

        $data = is_array($response['body']) ? ($response['body']['data'] ?? $response['body']) : null;

        if (is_array($data)) {
            if (! empty($data['status'])) {
                $nfse->status = $data['status'];
            }

            $nfse->applyApiData($data);

            if (! empty($data['erros'])) {
                $nfse->erros_json = json_encode($data['erros']);
            } elseif (in_array($data['status'] ?? null, ['autorizada', 'cancelada', 'substituida'], true) && empty($data['cancelamento']['falhou_em'])) {
                $nfse->erros_json = null;
            }
        }

        $nfse->save();

        if (is_array($data)) {
            $this->linkSubstitution($nfse, $data);
        }

        ($this->email)($nfse->charge);

        return $nfse;
    }

    /** aplicarVinculoSubstituicao: substituta autorizada → original vira "substituida". */
    private function linkSubstitution(FinChargesNfse $nfse, array $data): void
    {
        if ($nfse->status !== 'autorizada') {
            return;
        }

        $original = ($nfse->nfse_substituida_id ? FinChargesNfse::find($nfse->nfse_substituida_id) : null)
            ?? (! empty($data['nfse_substituida_id'])
                ? FinChargesNfse::where('nfse_uuid', $data['nfse_substituida_id'])->orderByDesc('fin_charges_nfse_id')->first()
                : null);

        if ($original?->status === 'autorizada') {
            $original->forceFill(['status' => 'substituida', 'erros_json' => null, 'updated_at' => now()])->save();
        }
    }
}
