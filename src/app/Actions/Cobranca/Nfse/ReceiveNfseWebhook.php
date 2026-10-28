<?php

namespace App\Actions\Cobranca\Nfse;

use App\Models\FinChargesNfse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * NfseEmissaoService::processarWebhook (legado): aplica o retorno final do emissor (autorizada | rejeitada | cancelada |
 * substituida) na NFS-e local, achada pelo UUID (o id_externo antigo, só o booking, pode colidir entre cobranças).
 */
class ReceiveNfseWebhook
{
    private const STATUSES = ['autorizada', 'rejeitada', 'cancelada', 'substituida'];

    public function __construct(private RefreshStatus $refresh) {}

    /**
     * @param  array  $payload  corpo do callback nfse.status.updated
     *
     * @throws InvalidArgumentException payload inválido (422)
     * @throws ModelNotFoundException NFS-e local não encontrada (404)
     */
    public function __invoke(array $payload, string $raw): FinChargesNfse
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data) || $data === []) {
            throw new InvalidArgumentException('Payload sem data');
        }

        $externalId = trim((string) ($data['id_externo'] ?? ''));
        $uuid = trim((string) ($data['id'] ?? ''));

        if (! in_array(trim((string) ($data['status'] ?? '')), self::STATUSES, true)) {
            throw new InvalidArgumentException('Status inválido para webhook');
        }

        if ($externalId === '' && $uuid === '') {
            throw new InvalidArgumentException('Payload sem id_externo e sem id');
        }

        $find = fn (string $column, string $value) => FinChargesNfse::where($column, $value)->orderByDesc('fin_charges_nfse_id')->first();
        $nfse = ($uuid !== '' ? $find('nfse_uuid', $uuid) : null)
            ?? ($externalId !== '' ? $find('id_externo', $externalId) : null);

        if (! $nfse) {
            throw new ModelNotFoundException('NFSe local não encontrada'
                .($externalId !== '' ? " (id_externo={$externalId})" : '')
                .($uuid !== '' ? " (uuid={$uuid})" : ''));
        }

        $nfse->forceFill(['response_json' => $raw, 'updated_at' => now()]);

        if ($uuid !== '' && blank($nfse->nfse_uuid)) {
            $nfse->nfse_uuid = $uuid;
        }

        $this->refresh->apply($nfse, $data);

        return $nfse;
    }
}
