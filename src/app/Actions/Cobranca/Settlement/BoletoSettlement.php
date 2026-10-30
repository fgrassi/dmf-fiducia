<?php

namespace App\Actions\Cobranca\Settlement;

use App\Actions\Cobranca\Documents\ChargeRecipients;
use App\Actions\Cobranca\Documents\DeclarationPdf;
use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\CmnFile;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\FinPaymentBoleto;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * saveBaixaBoleto (legado): grava os dados do pagamento, baixa a IC como boleto (status 2), gera a declaração
 * (fin_payment_boleto + PDF em uploads/declaracao), a envia ao cliente e emite a NFS-e marcada.
 */
class BoletoSettlement
{
    public function __construct(
        private DeclarationPdf $pdf,
        private ChargeRecipients $recipients,
        private IssueNfse $nfse,
    ) {}

    /**
     * Mesmos campos do FinChargesForm do legado; valores em pt-BR ("1.234,56").
     *
     * @param  array{ref_bank:int|string, received_value:string, interest?:?string, fine?:?string, discount?:?string,
     *     tax_boleto?:?string, discount_obs?:?string, payment_date:string, payment_confirmation:string,
     *     escritorio_id?:int|string|null, autoriza_liberacao_embarque?:bool}  $data
     * @param  ?list<string>  $emails  e-mails do cliente escolhidos na confirmação (null = todos)
     * @param  array  $nfse  campos do formulário de emissão
     * @return ?array{message:string, status:string} resultado da NFS-e
     */
    public function __invoke(FinCharges $charge, array $data, User $user, ?array $emails = null, array $nfse = []): ?array
    {
        if ($charge->status !== ChargeStatus::Open) {
            throw new DomainException('Somente cobranças em aberto podem receber baixa por boleto.');
        }

        $values = PaymentFields::parse($data);
        $dueTo = CarbonImmutable::parse($data['payment_confirmation'])->toDateString();

        $declaration = DB::transaction(function () use ($charge, $values, $dueTo, $user, $emails) {
            $charge->forceFill([
                ...$values,
                'discount_author' => $user->getKey(),
                'updated_by' => $user->getKey(),
                'updated_at' => now()->toDateString(),
                'status' => ChargeStatus::Boleto,
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
                'acao' => 'baixa_boleto',
                'historico' => 'Baixa por boleto. Valor recebido: '.number_format((float) $charge->received_value, 2, '.', '').'; Banco: '.($bank ? "{$bank->name} ({$bank->bank_number})" : '—').FinCharges::recipientsLogSummary($emails),
                'status_anterior' => ChargeStatus::Open->value,
                'status_novo' => ChargeStatus::Boleto->value,
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

            $file = CmnFile::forceCreate([
                'name' => 'declaracao_boleto_dmf_'.now()->format('YmdHis'),
                'subject' => "Declaração de Recebimento do Booking {$charge->embarque->booking}",
                'created_at' => now()->toDateString(),
            ]);
            $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();

            return FinPaymentBoleto::forceCreate(['ref_charge' => $charge->id, 'due_to' => $dueTo, 'ref_file' => $file->getKey()]);
        });

        $this->sendDeclaration($charge, $declaration->file->name);

        return $this->nfse->forCharge($charge, $nfse, IssueNfse::SETTLEMENT, $user->getKey());
    }

    /** Grava o PDF em uploads/declaracao e envia ao setor de cobrança e aos contatos do cliente. */
    private function sendDeclaration(FinCharges $charge, string $name): void
    {
        $path = "declaracao/{$name}.pdf";
        Storage::disk('tracking_uploads')->put($path, $this->pdf->render($charge, plain: false)->Output('', 'S'));

        $e = $charge->embarque;
        $type = (int) $charge->ref_type !== 1 ? ' - '.mb_strtoupper((string) $charge->type?->name) : '';
        // FinCharges::getClientName: exportador (EXPO) ou importador (IMPO), com o CNPJ
        $party = DB::table('pessoa')->where('pessoa_id', $e->impor_expor === 'e' ? $e->pessoa_id_cliente : $e->pessoa_id_importador)->first(['nome', 'cnpj']);
        $client = $party ? "{$party->nome} ({$party->cnpj})" : '';
        $subject = "DMF Logistics: Demonstrativo de Baixa do Boleto Bancário{$type}: {$e->booking} - "
            .preg_replace("/\r?\n/m", ' ', (string) $e->invoice)." - {$client}";

        Mail::send('mail.cobranca.boleto-declaration', [], fn ($mail) => $mail
            ->from(ChargeRecipients::COLLECTION_EMAIL, 'DMF Logistics do Brasil - Setor de Cobrança')
            ->to([ChargeRecipients::COLLECTION_EMAIL, ...array_keys(($this->recipients)($charge))])
            ->subject($subject)
            ->attach(Storage::disk('tracking_uploads')->path($path), ['as' => "{$name}.pdf"]));
    }
}
