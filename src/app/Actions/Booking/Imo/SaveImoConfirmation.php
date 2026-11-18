<?php

namespace App\Actions\Booking\Imo;

use App\Models\ControleImoCi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * saveImo / editDataImoConfirmacaoArmador (legado): data de recebimento pelo armador e o arquivo da confirmação (uploads/imo).
 * Diferença do legado: o nome do arquivo é gerado aqui e a extensão sai do conteúdo (PDF, JPG ou PNG), nunca do nome enviado.
 */
class SaveImoConfirmation
{
    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    private const EXTENSIONS = ['pdf', 'jpg', 'png'];

    public function __invoke(ControleImoCi $imo, ?string $date, ?UploadedFile $file): void
    {
        $changes = ['confirmacao_imo_armador' => $date];

        if ($file) {
            $extension = $file->guessExtension();
            abort_unless(in_array($extension, self::EXTENSIONS, true), 422, 'Envie um arquivo PDF, JPG ou PNG.');
            $name = "emb_{$imo->embarque_id}_".Str::random(16).".{$extension}";
            Storage::disk('tracking_uploads')->putFileAs('imo', $file, $name);
            $changes['ref_file_confirmacao_imo_armador'] = $name;
        }

        $imo->update($changes);
    }
}
