<?php

namespace App\Actions\Expenses;

use App\Models\ArquivoDespesa;
use App\Models\Despesa;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * addArquivoDespesa e removeArquivo (legado): anexos da despesa em `uploads/despesa` do tracking, com o nome
 * `ddmmaa` + `HHmm` (hora sem zero à esquerda, como o `G` do legado) + `_` + nome limpo. Remover apaga só o vínculo.
 */
class ManageExpenseFiles
{
    public const DIRECTORY = 'despesa';

    /** Divergência do legado, que aceitava qualquer tipo: a pasta é servida pelo tracking, então só documentos e imagens. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'xls', 'xlsx', 'doc', 'docx'];

    /**
     * @param  list<UploadedFile>  $files
     * @return list<string> nomes gravados
     *
     * @throws DomainException extensão fora de ALLOWED_EXTENSIONS (nada é gravado)
     */
    public function attach(Despesa $expense, array $files): array
    {
        foreach ($files as $file) {
            if (! in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_EXTENSIONS, true)) {
                throw new DomainException('Tipo de arquivo não permitido. Use: '.implode(', ', self::ALLOWED_EXTENSIONS).'.');
            }
        }

        $names = [];

        foreach ($files as $file) {
            $base = preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace([' ', '.'], '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));
            $name = now()->format('dmyGi').'_'.$base.'.'.strtolower($file->getClientOriginalExtension());

            Storage::disk('tracking_uploads')->putFileAs(self::DIRECTORY, $file, $name);
            $expense->files()->create(['arquivo' => $name]);
            $names[] = $name;
        }

        return $names;
    }

    /** @param  list<int|string>  $fileIds */
    public function remove(Despesa $expense, array $fileIds): void
    {
        ArquivoDespesa::query()->where('despesa_id', $expense->getKey())->whereKey($fileIds)->delete();
    }
}
