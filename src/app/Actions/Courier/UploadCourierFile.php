<?php

namespace App\Actions\Courier;

use App\Models\ControleCourier;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * uploadArquivo (legado): guarda o arquivo em uploads/courrier com a data e a hora na frente do nome e grava o nome no
 * courier. Um arquivo novo troca o nome gravado; o antigo continua na pasta, como no legado.
 *
 * Diferença do legado: só entram PDF, JPG e PNG, e a extensão gravada vem do conteúdo do arquivo, nunca do nome enviado
 * (o legado gravava o nome como veio, e `uploads` é servido por PHP); o resto do nome perde espaços e caracteres especiais.
 *
 * @throws DomainException
 */
class UploadCourierFile
{
    private const EXTENSIONS = ['pdf', 'jpg', 'png'];

    public function __invoke(ControleCourier $courier, UploadedFile $file): string
    {
        $extension = strtolower((string) $file->guessExtension());

        throw_unless(in_array($extension, self::EXTENSIONS, true), DomainException::class, 'Tipo de arquivo não permitido. Envie PDF, JPG ou PNG.');

        $base = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));
        $name = now()->format('dmyGi').'_'.$base.'.'.$extension;

        Storage::disk('tracking_uploads')->putFileAs('courrier', $file, $name);
        $courier->update(['arquivo' => $name]);

        return $name;
    }
}
