<?php

namespace App\Actions\Cobranca\Nfse;

use App\Models\FinCharges;
use App\Models\FinChargesNfse;
use DomainException;

/**
 * executeShow / sincronizarStatusNfsePendentes (legado): a última NFS-e da IC ainda pendente é consultada na API;
 * se já está autorizada e o e-mail não saiu, tenta enviá-lo. Falha da API não bloqueia a tela.
 */
class SyncPendingNfse
{
    private const TERMINAL = ['autorizada', 'rejeitada', 'cancelada', 'substituida'];

    public function __construct(
        private RefreshStatus $refresh,
        private SendAuthorizationEmail $email,
    ) {}

    /** @return bool se consultou a API (a listagem limita as consultas por página) */
    public function __invoke(FinCharges $charge): bool
    {
        $last = FinChargesNfse::where('ref_charge', $charge->getKey())->orderByDesc('fin_charges_nfse_id')->first();

        try {
            if ($last && filled($last->nfse_uuid) && ! in_array($last->status, self::TERMINAL, true)) {
                ($this->refresh)($last);

                return true;
            }

            if ($last?->status === 'autorizada' && ! $last->email_enviado_at) {
                ($this->email)($charge);
            }
        } catch (DomainException) {
            // API de NFS-e não configurada: fica o último status gravado
        }

        return false;
    }
}
