<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * O banco legado não tem chave estrangeira: antes de excluir, confere as tabelas que apontam para o registro.
 *
 * @property array<string, string> $references tabela => coluna que guarda a chave deste modelo
 */
trait HasReferences
{
    public function isReferenced(): bool
    {
        foreach ($this->references as $table => $column) {
            if (DB::table($table)->where($column, $this->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }
}
