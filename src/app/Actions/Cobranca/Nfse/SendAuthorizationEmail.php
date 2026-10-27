<?php

namespace App\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Documents\ChargeRecipients;
use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\FinCharges;
use App\Models\FinChargesNfse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * notificarAutorizacaoSePendente + Cobranca::enviarEmailNfseAutorizadasLote (legado): quando as NFS-e da IC
 * sem e-mail ficam finais, envia um e-mail com XML + DANFSe das autorizadas e marca email_enviado_at.
 * Cliente com prazo/boleto recebe o e-mail unificado com a Instrução de Cobrança; cliente em FinClientsException
 * (sem recálculo manual) só gera e-mail para o financeiro.
 */
class SendAuthorizationEmail
{
    public function __construct(
        private NfseApi $api,
        private ChargeRecipients $recipients,
    ) {}

    public function __invoke(FinCharges $charge): void
    {
        $pending = FinChargesNfse::where('ref_charge', $charge->id)->whereNull('email_enviado_at')->orderBy('fin_charges_nfse_id')->get();

        // uma nota: só autorizada; várias: espera todas ficarem finais
        if ($pending->isEmpty()
            || ($pending->count() === 1 && $pending->first()->status !== 'autorizada')
            || $pending->contains(fn ($n) => ! in_array($n->status, FinChargesNfse::FINAL_STATUSES, true))) {
            return;
        }

        try {
            $authorized = $pending->where('status', 'autorizada')->values();

            if ($authorized->isNotEmpty() && ! $this->send($charge, $authorized->all())) {
                return;
            }

            $pending->each(fn ($n) => $n->forceFill(['email_enviado_at' => now(), 'updated_at' => now()])->save());
        } catch (Throwable $e) {
            report($e); // como no legado, não interrompe status/baixa
        }
    }

    /**
     * E-mail com XML + DANFSe das notas (e a IC, para prazo/boleto). Também usado após o recálculo, com notas já enviadas.
     *
     * @param  list<FinChargesNfse>  $nfses
     */
    public function send(FinCharges $charge, array $nfses): bool
    {
        if (! NfseApi::configured()) {
            return false;
        }

        $e = $charge->embarque;
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;
        $reference = trim((string) ($bl?->dmf_ref ?: $e->invoice)) ?: '—';
        $attachments = [];
        $notes = [];

        foreach ($nfses as $nfse) {
            if (! $nfse->publicConsultationUrl() || blank($nfse->nfse_uuid)) {
                return false;
            }

            $xml = $this->api->xml($nfse->nfse_uuid);
            $pdf = $this->api->pdf($nfse->nfse_uuid);

            if (! $xml['ok'] || $xml['raw'] === '' || ! $pdf['ok'] || ! str_starts_with($pdf['raw'], '%PDF')) {
                return false;
            }

            $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $nfse->id_externo);
            $attachments[] = ['data' => $xml['raw'], 'name' => "nfse-{$base}.xml", 'mime' => 'application/xml'];
            $attachments[] = ['data' => $pdf['raw'], 'name' => "danfse-{$base}.pdf", 'mime' => 'application/pdf'];
            $notes[] = ['reference' => $reference, 'key' => $nfse->chave_acesso, 'url' => $nfse->publicConsultationUrl(), 'value' => $nfse->valor_servicos];
        }

        $client = DB::table('pessoa')->where('pessoa_id', ChargeRates::clientId($e))->first(['nome', 'razao_social']);
        $plural = count($nfses) > 1 ? 's' : '';
        $type = (int) $charge->ref_type !== 1 ? ' - '.mb_strtoupper((string) $charge->type?->name) : '';
        $suffix = "{$type}: {$e->booking} - ".preg_replace("/\r?\n/m", ' ', (string) $e->invoice).' - '.($client ? ($client->razao_social ?: $client->nome) : '');
        $recalculated = DB::table('fin_charges_log')->where('ref_charge', $charge->id)->where('acao', 'recalculo')->exists();
        $inException = DB::table('fin_clients_exception')->where('ref_client', $e->cliente_id)->exists()
            && ! $recalculated && ! EmissionGroups::carrierDoesNotRecalculate($e);
        $withInstruction = EmissionGroups::issuedWithInstruction($e);
        $ic = $withInstruction ? $this->instructionFile($charge) : null;

        if ($ic) {
            $attachments[] = $ic;
        }

        Mail::send('mail.cobranca.nfse-authorized', [
            'withInstruction' => $withInstruction,
            'sections' => $withInstruction ? InstructionEmailSections::build($charge, $inException) : null,
            'plural' => $plural !== '',
            'notes' => $notes,
            'hasIc' => (bool) $ic,
        ], function ($mail) use ($withInstruction, $plural, $suffix, $inException, $charge, $attachments) {
            $mail->from(ChargeRecipients::COLLECTION_EMAIL, 'DMF Logistics do Brasil - Setor de Cobrança')
                ->to([ChargeRecipients::COLLECTION_EMAIL, ...($inException ? [] : array_keys(($this->recipients)($charge)))])
                ->subject($withInstruction
                    ? "DMF Logistics: Instrução de Cobrança e NFS-e Autorizada{$plural}{$suffix}"
                    : "DMF Logistics: NFS-e Autorizada{$plural}{$suffix}");

            foreach ($attachments as $a) {
                isset($a['path'])
                    ? $mail->attach($a['path'], ['as' => $a['name']])
                    : $mail->attachData($a['data'], $a['name'], ['mime' => $a['mime']]);
            }
        });

        return true;
    }

    /** resolverArquivoIcCobranca: PDF da IC mais recente (cmn_files "ic…") que exista em uploads/cobranca. */
    private function instructionFile(FinCharges $charge): ?array
    {
        $disk = Storage::disk('tracking_uploads');
        $name = DB::table('fin_charges_files as x')->join('cmn_files as f', 'f.id', '=', 'x.ref_file')
            ->where('x.ref_charge', $charge->id)->where('f.name', 'like', 'ic%')->orderByDesc('x.id')->pluck('f.name')
            ->first(fn ($n) => $disk->exists("cobranca/{$n}.pdf"));

        return $name ? ['path' => $disk->path("cobranca/{$name}.pdf"), 'name' => "{$name}.pdf"] : null;
    }
}
