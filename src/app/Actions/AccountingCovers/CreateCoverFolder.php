<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use App\Models\CapaProcessoArquivo;
use DomainException;
use Illuminate\Support\Facades\DB;

/** novaPasta / DmfUtil::novaCapaProcesso (legado): abre a pasta do mês seguinte e fecha a aberta, se não restar capa solta. */
class CreateCoverFolder
{
    private const MONTHS = ['JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];

    /** @throws DomainException */
    public function __invoke(): CapaProcessoArquivo
    {
        $unarchived = CapaProcesso::whereNull('capa_processo_arquivo_id')->exists()
            || DB::table('capa_processo_manual')->whereNull('capa_processo_arquivo_id')->exists();

        if ($unarchived) {
            throw new DomainException('Não é possível criar uma Nova Caixa. Existem capas não arquivadas!');
        }

        $current = CapaProcessoArquivo::where('status', 1)->orderByDesc('ano')->orderByDesc('mes')->first()
            ?? throw new DomainException('Não há caixa aberta para seguir.');

        [$month, $year] = $current->mes == 12 ? [1, $current->ano + 1] : [$current->mes + 1, $current->ano];

        return DB::transaction(function () use ($current, $month, $year) {
            $folder = CapaProcessoArquivo::create(['nome' => self::MONTHS[$month - 1].'/'.$year, 'ano' => $year, 'mes' => $month, 'status' => 1]);
            $current->update(['status' => 0]);

            return $folder;
        });
    }
}
