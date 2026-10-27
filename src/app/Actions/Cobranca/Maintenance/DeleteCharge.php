<?php

namespace App\Actions\Cobranca\Maintenance;

use App\Actions\Cobranca\Items\DeleteItems;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * deleteCobranca (legado): exclui a IC com itens, arquivos, boletos e cotações negociadas,
 * registra no histórico e no rastreamento do embarque e avisa o setor de cobrança.
 */
class DeleteCharge
{
    public const COLLECTION_EMAIL = 'cobranca@dmflog.com.br';

    /** Botão "Excluir" e "Remover recibo": manager/admin/excluir_cobranca. */
    public static function allowed(?User $user): bool
    {
        return (bool) $user?->hasLegacyCredential(['manager', 'excluir_cobranca']);
    }

    /** FinCharges::getMotivosImpedemExclusao. @return list<string> */
    public static function blockingReasons(FinCharges $charge): array
    {
        $reasons = [];

        if (DeleteItems::hasIssuedNfse($charge)) {
            $reasons[] = 'Já existe NFS-e emitida (autorizada) para esta cobrança.';
        }

        $receipts = $charge->receipts()->orderBy('id')->get();

        if ($receipts->isNotEmpty()) {
            $details = $receipts->map(fn ($r) => "nº {$r->id}"
                .($r->valor !== null ? ', valor R$ '.number_format((float) $r->valor, 2, ',', '.') : ''));
            $reasons[] = $receipts->count() === 1
                ? "Existe recibo de pagamento vinculado a esta cobrança ({$details[0]}). Remova o recibo antes de excluir a cobrança."
                : "Existem {$receipts->count()} recibos de pagamento vinculados a esta cobrança ({$details->implode('; ')}). Remova os recibos antes de excluir a cobrança.";
        }

        $boletoReceipts = DB::table('recibo_boleto')->where('cobranca_id', $charge->id)->count();

        if ($boletoReceipts > 0) {
            $reasons[] = $boletoReceipts === 1
                ? 'Existe recibo de boleto vinculado a esta cobrança.'
                : "Existem {$boletoReceipts} recibos de boleto vinculados a esta cobrança.";
        }

        $nfFiles = $charge->nfFiles()->count();

        if ($nfFiles > 0) {
            $reasons[] = $nfFiles === 1
                ? 'Existe arquivo de nota fiscal vinculado a esta cobrança.'
                : "Existem {$nfFiles} arquivos de nota fiscal vinculados a esta cobrança.";
        }

        return $reasons;
    }

    public function __invoke(FinCharges $charge, ?User $user): void
    {
        if (! self::allowed($user)) {
            throw new DomainException('Você não tem permissão para excluir cobranças.');
        }

        if ($reasons = self::blockingReasons($charge)) {
            throw new DomainException('Não é possível excluir a cobrança. '.implode(' ', $reasons));
        }

        $embarque = $charge->embarque;
        $message = "A cobranca referente ao embarque {$embarque->booking} foi excluída pelo usuário "
            .$user->getFilamentName().' em '.now()->format('H:i:s d/m/Y');

        DB::transaction(function () use ($charge, $embarque, $message, $user) {
            $charge->items()->delete();
            $charge->files()->delete();
            $charge->boletoPayments()->delete();
            $charge->customRates()->delete();

            $status = $charge->status->value;
            $charge->delete();

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'exclusao',
                'historico' => $message,
                'status_anterior' => $status,
                'created_at' => now(),
                'created_by' => $user->getKey(),
            ]);

            DB::table('rastreamento')->insert([
                'assunto' => 'Cobranca Excluída',
                'evento' => 'd',
                'historico' => $message,
                'ref_embarque' => $embarque->embarque_id,
                'created_at' => now()->toDateString(),
                'created_time' => now()->format('H:i:s'),
                'created_by' => $user->getKey(),
            ]);

            $embarque->forceFill(['ctr_desp' => 1])->save();
        });

        Mail::raw($message, fn ($mail) => $mail->to(self::COLLECTION_EMAIL)->subject('Cobranca Excluída'));
    }
}
