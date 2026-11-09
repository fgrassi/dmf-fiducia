<?php

namespace App\Actions\ControlePa;

use App\Models\CmnFile;
use App\Models\ControlePa;
use App\Models\EmbEmbarqueFile;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * addFiArmador / addDocPagamentoArmador / addDocReciboArmador (legado): guarda o arquivo em uploads/docs_emb e liga à reserva
 * com o tipo da etapa. Anexar a FI do armador também marca flag_fi_armador, que libera o pagamento. O legado aceitava qualquer
 * extensão; aqui só documentos e imagens, porque a pasta é servida pelo site.
 */
class AttachControlePaFile
{
    private const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    /** @throws DomainException */
    public function __invoke(ControlePa $row, int $fileType, UploadedFile $upload, int $userId): void
    {
        if (! $row->canAttach($fileType)) {
            throw new DomainException('Esta etapa ainda não foi liberada.');
        }

        if (! in_array(strtolower($upload->getClientOriginalExtension()), self::ALLOWED, true)) {
            throw new DomainException('Formato de arquivo não permitido.');
        }

        $name = now()->format('dmyGi').'_'.preg_replace('/[^A-Za-z0-9-._\-]/', '', str_replace(' ', '_', $upload->getClientOriginalName()));

        DB::transaction(function () use ($row, $fileType, $upload, $name, $userId) {
            $file = CmnFile::forceCreate([
                'uid' => $row->embarque_id,
                'name' => $name,
                'subject' => 'Documento Anexo a Reserva',
                'created_at' => now()->toDateString(),
                'created_by' => $userId,
            ]);
            $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();

            Storage::disk('tracking_uploads')->putFileAs('docs_emb', $upload, $name);

            EmbEmbarqueFile::forceCreate(['ref_embarque' => $row->embarque_id, 'ref_file' => $file->getKey(), 'ref_file_type' => $fileType]);

            if ($fileType === EmbEmbarqueFile::CARRIER_INVOICE) {
                $row->forceFill(['flag_fi_armador' => 1])->save();
            }
        });
    }
}
