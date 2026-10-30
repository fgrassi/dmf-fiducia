<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use App\Models\CapaProcessoArquivo;
use App\Models\CapaProcessoManual;

/** arquivarCapa (legado): move as capas, normais ou manuais, para a pasta escolhida; sem conferir o estado atual, como lá. */
class ArchiveCovers
{
    /**
     * @param  array<int, int|string>  $coverIds
     * @param  class-string<CapaProcesso|CapaProcessoManual>  $model
     */
    public function __invoke(array $coverIds, CapaProcessoArquivo $folder, string $model = CapaProcesso::class): int
    {
        return $model::whereKey($coverIds)->update(['capa_processo_arquivo_id' => $folder->getKey()]);
    }
}
