<?php

namespace App\Actions\Companies;

use App\Models\Pessoa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** pessoa/delete (legado): apaga a empresa e seus vínculos; devolve false se ela já está em uso por outros registros (FK). */
class DeleteCompany
{
    public function __invoke(Pessoa $company): bool
    {
        try {
            return DB::transaction(function () use ($company) {
                foreach (['pessoa_tipo_pessoa', 'pessoa_armador', 'pessoa_sede'] as $table) {
                    DB::table($table)->where('pessoa_id', $company->getKey())->delete();
                }

                return (bool) $company->delete();
            });
        } catch (QueryException) {
            return false;
        }
    }
}
