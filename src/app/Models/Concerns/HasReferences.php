<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * O banco legado não tem chave estrangeira: antes de excluir, confere as tabelas que apontam para o registro.
 *
 * @property array<string, string|list<string>> $references tabela => coluna(s) que guardam a chave deste modelo
 */
trait HasReferences
{
    public function isReferenced(): bool
    {
        foreach ($this->references as $table => $columns) {
            $query = DB::table($table)->where(function ($query) use ($columns) {
                foreach ((array) $columns as $column) {
                    $query->orWhere($column, $this->getKey());
                }
            });

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }
}
