<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * executeGerarPdfDebitNote / executeGerarPdfCreditNote (legado): gera o PDF da nota, guarda em uploads/debitNotes ou
 * uploads/creditNotes, cadastra o arquivo (cmn_files) e aponta a nota para ele. Cada geração cria um novo cmn_files e
 * sobrescreve o PDF de mesmo nome, como o legado.
 */
class GenerateNotePdf
{
    public function __construct(private NotePdf $pdf) {}

    /** @return string caminho do PDF no disco `tracking_uploads` */
    public function __invoke(NoteType $type, int $noteId, int $userId, bool $hideValues = false): string
    {
        $name = $this->pdf->fileName($type, $noteId);
        $path = self::path($type, $name);
        $booking = DB::table('embarque')->where('embarque_id', DB::table($type->table())->where('id', $noteId)->value('ref_embarque'))->value('booking');

        Storage::disk('tracking_uploads')->put($path, $this->pdf->render($type, $noteId, $hideValues)->Output('', 'S'));

        DB::transaction(function () use ($type, $noteId, $userId, $name, $booking) {
            $fileId = DB::table('cmn_files')->insertGetId([
                'name' => $name,
                'subject' => "{$type->label()} {$booking}",
                'created_at' => now()->toDateString(),
                'created_by' => $userId,
                'uid' => '',
            ]);

            DB::table('cmn_files')->where('id', $fileId)->update(['uid' => sha1((string) $fileId)]);
            DB::table($type->table())->where('id', $noteId)->update(['ref_file' => $fileId]);
        });

        return $path;
    }

    /** uploads/debitNotes/<nome>.pdf ou uploads/creditNotes/<nome>.pdf (relativo ao disco de uploads). */
    public static function path(NoteType $type, string $name): string
    {
        return ($type === NoteType::Debit ? 'debitNotes' : 'creditNotes')."/{$name}.pdf";
    }
}
