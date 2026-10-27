<?php

namespace App\Actions\Cobranca\Receipts;

use App\Actions\Cobranca\Items\DeleteItems;
use App\Actions\Cobranca\Maintenance\DeleteCharge;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\CmnFile;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * deleteRecibo (legado): remove todos os recibos e declarações da IC (e seus cmn_files)
 * e a cobrança volta para "em aberto". Bloqueado se houver NFS-e autorizada.
 */
class DeleteReceipts
{
    public static function allowed(FinCharges $charge, ?User $user): bool
    {
        return DeleteCharge::allowed($user)
            && in_array($charge->status, [ChargeStatus::Paid, ChargeStatus::Boleto], true)
            && ! DeleteItems::hasIssuedNfse($charge);
    }

    public function __invoke(FinCharges $charge, ?User $user): void
    {
        if (DeleteItems::hasIssuedNfse($charge)) {
            throw new DomainException('Não é possível remover o recibo: já existe NFS-e emitida (autorizada).');
        }

        if (! self::allowed($charge, $user)) {
            throw new DomainException('Você não tem permissão para remover o recibo desta cobrança.');
        }

        $message = "O recibo/declaração referente ao embarque {$charge->embarque->booking}, HBL {$charge->bl?->bl} foi excluído pelo usuário "
            .$user->getFilamentName().' em '.now()->format('H:i:s d/m/Y');

        DB::transaction(function () use ($charge, $message, $user) {
            $status = $charge->status->value;

            foreach ([$charge->receipts()->get(), $charge->boletoPayments()->get()] as $rows) {
                foreach ($rows as $row) {
                    $row->delete();
                    CmnFile::whereKey($row->ref_file)->delete();
                }
            }

            $charge->forceFill(['status' => ChargeStatus::Open])->save();

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'remocao_baixa',
                'historico' => 'Remoção de baixa / recibo. Status voltou para em aberto.',
                'status_anterior' => $status,
                'status_novo' => ChargeStatus::Open->value,
                'created_at' => now(),
                'created_by' => $user->getKey(),
            ]);

            DB::table('rastreamento')->insert([
                'assunto' => 'Recibo/Declaração Excluída',
                'evento' => 'd',
                'historico' => $message,
                'ref_embarque' => $charge->ref_booking,
                'created_at' => now()->toDateString(),
                'created_time' => now()->format('H:i:s'),
                'created_by' => $user->getKey(),
            ]);
        });

        Mail::raw($message, fn ($mail) => $mail->to(DeleteCharge::COLLECTION_EMAIL)->subject('Recibo/Declaração Excluída'));
    }
}
