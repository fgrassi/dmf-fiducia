<?php

namespace App\Actions\Booking\Ams;

use App\Enums\AmsDestination;
use App\Models\AmsRegistration;
use App\Models\Embarque;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * addArquivoAfrica / addArquivoUae e confirmaAmsAfrica / confirmaAmsUae (legado): guarda o arquivo da confirmação do agente
 * (uploads/ams_africa ou ams_uae), registra a data do recebimento e conclui a reserva. O nome do arquivo é gerado aqui e a
 * extensão sai do conteúdo (PDF, JPG ou PNG), nunca do nome enviado.
 */
class ConfirmAms
{
    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    private const EXTENSIONS = ['pdf', 'jpg', 'png'];

    public const CONFIRMED = 3;

    public function __invoke(AmsDestination $destination, Embarque $booking, AmsRegistration $registration, UploadedFile $file): void
    {
        $extension = $file->guessExtension();
        abort_unless(in_array($extension, self::EXTENSIONS, true), 422, 'Envie um arquivo PDF, JPG ou PNG.');
        $name = date('dmyGi').'_'.Str::random(12).".{$extension}";

        Storage::disk('tracking_uploads')->putFileAs($destination->directory(), $file, $name);

        DB::transaction(function () use ($destination, $booking, $registration, $name) {
            $booking->forceFill([$destination->statusColumn() => self::CONFIRMED])->save();
            $registration->forceFill(['data_confirmacao' => now(), 'arquivo' => $name])->save();
        });
    }
}
