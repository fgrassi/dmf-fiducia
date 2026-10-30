<?php

namespace App\Actions\Cobranca\Settlement;

use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Receipts\GenerateReceipts;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * update (legado, "Baixa Bancária"): grava os dados do pagamento, baixa a IC como paga (status 1)
 * e gera os recibos (um por grupo de NFS-e), enviados ao cliente; depois emite a NFS-e marcada (uma por grupo).
 */
class BankSettlement
{
    public function __construct(
        private GenerateReceipts $receipts,
        private IssueNfse $nfse,
    ) {}

    /**
     * @param  array  $data  campos do FinChargesForm (ver PaymentFields)
     * @param  ?list<string>  $emails  e-mails do cliente escolhidos na confirmação (null = todos)
     * @param  array  $nfse  campos do formulário de emissão (NfseEmissaoForm)
     * @return array{warning:?string, nfse:?array{message:string, status:string}} aviso dos recibos e resultado da NFS-e
     */
    public function __invoke(FinCharges $charge, array $data, User $user, ?array $emails = null, array $nfse = []): array
    {
        if ($charge->status !== ChargeStatus::Open) {
            throw new DomainException('Somente cobranças em aberto podem receber baixa bancária.');
        }

        $values = PaymentFields::parse($data);

        DB::transaction(function () use ($charge, $values, $user, $emails) {
            $charge->forceFill([
                ...$values,
                'discount_author' => $user->getKey(),
                'updated_by' => $user->getKey(),
                'updated_at' => now()->toDateString(),
                'status' => ChargeStatus::Paid,
            ]);
            PaymentFields::releaseShipment($charge, $user);

            if ((float) $charge->discount > 0) {
                $charge->discount_status = 1;
            }

            if ($emails !== null) {
                $charge->selectRecipients($emails);
            }

            $charge->save();

            $bank = DB::table('cmn_banks')->where('id', $charge->ref_bank)->first();

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'baixa_bancaria',
                'historico' => 'Baixa bancária. Valor recebido: '.number_format((float) $charge->received_value, 2, '.', '').'; Banco: '.($bank ? "{$bank->name} ({$bank->bank_number})" : '—').FinCharges::recipientsLogSummary($emails),
                'status_anterior' => ChargeStatus::Open->value,
                'status_novo' => ChargeStatus::Paid->value,
                'created_at' => now(),
                'created_by' => $user->getKey(),
            ]);

            DB::table('transborda')->insert([
                'usuario_id' => 1,
                'embarque_id' => $charge->ref_booking,
                'data_reg' => now()->toDateString(),
                'descr' => 'Payment received',
                'ref_frase_transborda' => 59,
            ]);
        });

        // depois do commit, como o legado: os recibos (MyISAM), o e-mail e a NFS-e não voltam atrás
        $receipts = ($this->receipts)($charge);

        return [
            'warning' => $receipts['warning'],
            'nfse' => $this->nfse->forCharge($charge, $nfse, IssueNfse::SETTLEMENT, $user->getKey(), $receipts['groups']),
        ];
    }
}
