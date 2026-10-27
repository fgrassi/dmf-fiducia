<?php

namespace App\Actions\Cobranca\Recalculation;

use App\Actions\Cobranca\Documents\InstructionPdf;
use App\Actions\Cobranca\Documents\InsurancePdf;
use App\Actions\Cobranca\Nfse\EmissionGroups;
use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Nfse\NfseAlert;
use App\Actions\Cobranca\Nfse\SendAuthorizationEmail;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Actions\Cobranca\Nfse\SubstituteNfse;
use App\Models\FinCharges;
use App\Models\FinChargesNfse;
use Throwable;

/**
 * Cobranca::enviarAposRecalculoCobranca + emitirNaGeracaoCobranca(aposRecalculo) (legado): depois do recálculo,
 * substitui as NFS-e autorizadas (ou emite, se o cliente emite com a IC e ainda não tem), regenera o PDF da IC e
 * reenvia ao cliente. Cliente com prazo/boleto e nota autorizada recebe IC + NFS-e juntas; com nota ainda na fila,
 * a IC é gerada sem e-mail (o e-mail sai quando a substituta autorizar).
 */
class AfterRecalculation
{
    public function __construct(
        private InstructionPdf $pdf,
        private InsurancePdf $insurancePdf,
        private IssueNfse $issue,
        private SubstituteNfse $substitute,
        private SendAuthorizationEmail $email,
        private NfseAlert $alert,
    ) {}

    /** @return ?string erro da NFS-e (já avisado ao financeiro por e-mail) */
    public function __invoke(FinCharges $charge, ?int $userId, string $reason = '99', string $justification = ''): ?string
    {
        // seguro: só regenera a IC de seguro e reenvia (recalculado libera o cliente em exceção), sem NFS-e
        if ($charge->embarque->tipo_embarque === 's') {
            ($this->insurancePdf)($charge, recalculated: true);

            return null;
        }

        $error = $this->nfse($charge, $userId, $reason, $justification);

        if ($error !== null) {
            ($this->alert)([NfseAlert::row($charge, $error)]);
        }

        $deferred = $this->deferEmail($charge);

        // substituição/emissão em andamento: não manda e-mail com a nota antiga
        if ($deferred && FinChargesNfse::where('ref_charge', $charge->id)->whereIn('status', ['enfileirada', 'processando'])->exists()) {
            ($this->pdf)($charge, sendEmail: false);

            return $error;
        }

        $authorized = $deferred ? FinChargesNfse::where('ref_charge', $charge->id)->where('status', 'autorizada')->orderByDesc('fin_charges_nfse_id')->get() : collect();

        if ($authorized->isNotEmpty()) {
            ($this->pdf)($charge, sendEmail: false);

            try {
                $this->email->send($charge, $authorized->all());
            } catch (Throwable $e) {
                report($e);
            }

            return $error;
        }

        ($this->pdf)($charge, sendEmail: true, recalculated: true);

        return $error;
    }

    /** emitirNaGeracaoCobranca(aposRecalculo = true). @return ?string erro */
    private function nfse(FinCharges $charge, ?int $userId, string $reason, string $justification): ?string
    {
        $e = $charge->embarque;

        if (! in_array($e->impor_expor, ['i', 'e'], true) || ServiceTaker::blockedByRule($e)) {
            return null;
        }

        try {
            if (FinChargesNfse::hasActiveEmission($charge->id)) {
                return $this->substitute->afterRecalculation($charge, $userId, $reason, $justification)['error'];
            }

            return EmissionGroups::issuedWithInstruction($e) ? $this->issue->withInstruction($charge, $userId, sendEmail: false) : null;
        } catch (Throwable $ex) {
            report($ex);

            return $ex->getMessage();
        }
    }

    /** deveAdiarEmailInstrucaoCobranca(aposRecalculo = true): a NFS-e sai com a IC e o cliente não bloqueia a emissão. */
    private function deferEmail(FinCharges $charge): bool
    {
        $e = $charge->embarque;

        return in_array($e->impor_expor, ['i', 'e'], true) && EmissionGroups::issuedWithInstruction($e) && ! ServiceTaker::blockedByRule($e);
    }
}
