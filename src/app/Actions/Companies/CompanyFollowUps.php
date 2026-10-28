<?php

namespace App\Actions\Companies;

use App\Models\Pessoa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** pessoa/listFups (legado): follow ups da DMF, follow ups do vendedor e visitas da empresa, do mais recente ao mais antigo. */
class CompanyFollowUps
{
    /** @return Collection<int, array{tipo:string, data_registro:?string, relato:?string, data_ocorrencia:?string, responsavel:?string}> */
    public function __invoke(Pessoa $company): Collection
    {
        $id = $company->getKey();

        $dmf = DB::table('fu_histories as fh')
            ->join('fu_process as fp', 'fh.ref_process', '=', 'fp.id')
            ->join('usuario as u', 'fh.created_by', '=', 'u.usuario_id')
            ->where('fp.ref_pessoa', $id)
            ->selectRaw("'Follow Up DMF' as tipo, fh.created_at as data_registro, fh.comentarios as relato, fh.created_at as data_ocorrencia, u.nome as responsavel");

        $seller = DB::table('cmn_client_fup as ccf')
            ->join('usuario as u', 'ccf.ref_user', '=', 'u.usuario_id')
            ->where('ccf.ref_pessoa', $id)
            ->selectRaw("'Follow Up Vendedor', ccf.created_at, ccf.descr, ccf.data_ocorrencia, u.nome");

        $visits = DB::table('crm_agenda as ca')
            ->join('vendedor as v', 'ca.ref_vendedor', '=', 'v.vendedor_id')
            ->where('ca.ref_pessoa', $id)
            ->selectRaw("'Visita', ca.visita, 'Visita', ca.visita, v.nome");

        return $dmf->union($seller)->union($visits)
            ->orderByDesc('data_registro')
            ->get()
            ->map(fn ($row) => (array) $row);
    }
}
