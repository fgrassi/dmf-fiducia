<?php

namespace App\Actions\Booking\ShippingInstruction;

use App\Models\CmnFile;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * saveDocs com ctr_instr = 1 (legado): guarda o arquivo em uploads/docs_emb como "emb_<reserva>_<nome>" (o nome enviado só perde os
 * caracteres especiais), cria o cmn_files e liga à reserva como arquivo da instrução de embarque.
 */
class AddInstructionFile
{
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

    public function __invoke(Embarque $booking, int $typeId, UploadedFile $file, int $userId): EmbEmbarqueFile
    {
        $extension = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($extension, self::EXTENSIONS, true), 422, 'Tipo de arquivo não aceito.');

        $clean = preg_replace('/[^A-Za-z0-9-._\-]/', '', str_replace(' ', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));
        $name = 'emb_'.preg_replace('/[^A-Za-z0-9-._\-]/', '', str_replace(' ', '_', (string) $booking->booking)).'_'.($clean !== '' ? $clean : 'arquivo').".{$extension}";

        Storage::disk('tracking_uploads')->putFileAs('docs_emb', $file, $name);

        return DB::transaction(function () use ($booking, $typeId, $name, $userId) {
            $stored = (new CmnFile)->forceFill(['uid' => 1, 'name' => $name, 'created_at' => now()->toDateString(), 'created_by' => $userId]);
            $stored->save();
            $stored->forceFill(['uid' => sha1((string) $stored->getKey()), 'subject' => 'Documento Anexo a Reserva'])->save();

            return tap((new EmbEmbarqueFile)->forceFill([
                'ref_embarque' => $booking->getKey(),
                'ref_file' => $stored->getKey(),
                'ref_file_type' => $typeId,
                'ctr_instr' => 1,
            ]))->save();
        });
    }
}
