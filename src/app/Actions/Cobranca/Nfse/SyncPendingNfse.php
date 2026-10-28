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
        if ($this->refresh($charge)) {
            return true;
        }

        $this->retryEmail($charge);

        return false;
    }

    /** Primeiro laço do sincronizarStatusNfsePendentes: consulta a API da última NFS-e ainda não final. @return bool se consultou */
    public function refresh(FinCharges $charge): bool
    {
        $last = $this->last($charge);

        try {
            if ($last && filled($last->nfse_uuid) && ! in_array($last->status, self::TERMINAL, true)) {
                ($this->refresh)($last);

                return true;
            }
        } catch (DomainException) {
            // API de NFS-e não configurada: fica o último status gravado
        }

        return false;
    }

    /** Segundo laço: nota já autorizada cujo e-mail ainda não saiu. @return bool se tentou enviar */
    public function retryEmail(FinCharges $charge): bool
    {
        $last = $this->last($charge);

        if ($last?->status !== 'autorizada' || $last->email_enviado_at) {
            return false;
        }

        try {
            ($this->email)($charge);
        } catch (DomainException) {
        }

        return true;
    }

    private function last(FinCharges $charge): ?FinChargesNfse
    {
        return FinChargesNfse::where('ref_charge', $charge->getKey())->orderByDesc('fin_charges_nfse_id')->first();
    }
}
