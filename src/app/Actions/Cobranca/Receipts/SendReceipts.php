<?php

namespace App\Actions\Cobranca\Receipts;

use App\Actions\Cobranca\Documents\ChargeRecipients;
use App\Actions\Cobranca\Nfse\EmissionGroups;
use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Cobranca::enviarEmailRecibos (legado): um e-mail com todos os PDFs de recibo para cobranca@ e os contatos do cliente.
 * No reenvio (reenviaRecibo) o texto não fala da NFS-e.
 */
class SendReceipts
{
    public function __construct(private ChargeRecipients $recipients) {}

    /** @param  list<string>  $files  nomes em uploads/recibo */
    public function __invoke(FinCharges $charge, array $files, bool $resend = false): void
    {
        if ($files === []) {
            return;
        }

        $e = $charge->embarque;
        $client = DB::table('pessoa')->where('pessoa_id', ChargeRates::clientId($e))->first(['nome', 'razao_social']);
        $plural = count($files) > 1 ? 's' : '';
        $type = (int) $charge->ref_type !== 1 ? ' - '.mb_strtoupper((string) $charge->type?->name) : '';
        $subject = "DMF Logistics: Recibo{$plural} de Pagamento {$type}: {$e->booking} - "
            .preg_replace("/\r?\n/m", ' ', (string) $e->invoice).' - '.($client ? ($client->razao_social ?: $client->nome) : '');

        Mail::send('mail.cobranca.receipts', [
            'plural' => $plural !== '',
            // prazo/boleto ou armador sem recálculo: a NFS-e já foi com a IC
            'nfseLater' => ! $resend && ! EmissionGroups::issuedWithInstruction($e),
        ], function ($mail) use ($charge, $files, $subject) {
            $mail->from(ChargeRecipients::COLLECTION_EMAIL, 'DMF Logistics do Brasil - Setor de Cobrança')
                ->to([ChargeRecipients::COLLECTION_EMAIL, ...array_keys(($this->recipients)($charge))])
                ->subject($subject);

            foreach ($files as $file) {
                $mail->attach(Storage::disk('tracking_uploads')->path("recibo/{$file}"), ['as' => $file]);
            }
        });
    }
}
