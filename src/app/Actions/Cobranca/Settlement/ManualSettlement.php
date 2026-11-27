<?php

namespace App\Actions\Cobranca\Settlement;

use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Enums\Alert;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * baixaManual (legado): baixa a IC com justificativa (status 3), opcionalmente libera o embarque
 * avisa o financeiro e emite a NFS-e marcada. Numa IC com baixa por boleto, troca a baixa e apaga a declaração.
 */
class ManualSettlement
{
    public function __construct(private IssueNfse $nfse) {}

    /**
     * @param  ?list<string>  $emails  e-mails do cliente escolhidos na confirmação (null = todos)
     * @param  array  $nfse  campos do formulário de emissão
     * @return ?array{message:string, status:string} resultado da NFS-e
     */
    public function __invoke(FinCharges $charge, string $justification, ?int $officeId, bool $releaseShipment, User $user, ?array $emails = null, array $nfse = []): ?array
    {
        if (! $charge->status->allowsBankOrManualSettlement()) {
            throw new DomainException('Somente cobranças em aberto podem receber baixa manual.');
        }

        $justification = trim($justification);

        if (mb_strlen($justification) < 3 || mb_strlen($justification) > 1000) {
            throw new DomainException('O comprimento de Justificativa precisa ser entre 3 e 1000.');
        }

        $obs = "{$justification}\rResponsável: {$user->getFilamentName()}\rData: ".now()->format('d/m/Y');

        $previous = $charge->status;
        $swap = $previous === ChargeStatus::Boleto;

        DB::transaction(function () use ($charge, $justification, $obs, $officeId, $releaseShipment, $user, $emails, $previous, $swap) {
            if ($swap) {
                BoletoSettlement::removeDeclarations($charge);
            }

            $charge->forceFill([
                'status' => ChargeStatus::ManualSettlement,
                'obs' => $obs,
                'escritorio_id' => $officeId ?? $charge->escritorio_id,
                'updated_at' => now()->toDateString(),
                'due_date' => $charge->due_date ?? now()->toDateString(),
                'updated_by' => $user->getKey(),
            ]);

            // liberação do embarque só se ainda não foi enviada (status_envio 1)
            if ($releaseShipment && (int) $charge->status_envio !== 1) {
                $charge->forceFill([
                    'status_envio' => 0,
                    'user' => $user->getFilamentName(),
                    'hora' => now()->format('H:i:s'),
                    'data_envio' => now()->toDateString(),
                    'autoriza_liberacao_embarque' => 'on',
                ]);
            }

            if ($emails !== null) {
                $charge->selectRecipients($emails);
            }

            $charge->save();

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'baixa_manual',
                'historico' => ($swap ? 'Troca de baixa por boleto para baixa manual' : 'Baixa manual').". Justificativa: {$justification}".FinCharges::recipientsLogSummary($emails),
                'status_anterior' => $previous->value,
                'status_novo' => ChargeStatus::ManualSettlement->value,
                'created_at' => now(),
                'created_by' => $user->getKey(),
            ]);
        });

        $what = $swap ? 'teve a baixa por boleto trocada por baixa manual' : 'sofreu baixa manual';

        Alert::ManualSettlement->send(
            'DMF Logistics: Cobrança com Baixa Manual',
            "A cobranca do embarque {$charge->embarque->booking} {$what}, com a seguinte justificativa e responsável: ".e($obs),
        );

        return $this->nfse->forCharge($charge, $nfse, IssueNfse::SETTLEMENT, $user->getKey());
    }
}
