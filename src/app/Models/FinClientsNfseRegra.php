<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Regra de NFS-e do cliente (fin_clients_nfse_regra): tomador padrão, bloqueio da emissão e NFS por grupo de despesas. */
class FinClientsNfseRegra extends Model
{
    protected $table = 'fin_clients_nfse_regra';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'bloquear_emissao' => 'boolean',
            'separar_nf_grupo_despesas' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'ref_client', 'pessoa_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'usuario_id');
    }

    /** Grupos de despesas (regra_despesa_cobranca) com NFS separada. */
    public function expenseRules(): BelongsToMany
    {
        return $this->belongsToMany(RegraDespesaCobranca::class, 'fin_clients_nfse_regra_despesa', 'ref_nfse_regra', 'regra_despesa_cobranca_id');
    }
}
