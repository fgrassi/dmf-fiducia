<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pessoa extends Model
{
    protected $table = 'pessoa';

    protected $primaryKey = 'pessoa_id';

    public $timestamps = false;

    /** Contatos da empresa (usuario via pessoa_usuario). */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pessoa_usuario', 'pessoa_id', 'usuario_id', 'pessoa_id', 'usuario_id');
    }

    /** Pessoa::getUltimaConsulta (legado): a consulta ativa mais recente. */
    public function currentCnpjReview(): HasOne
    {
        return $this->hasOne(ConsultaCnpj::class, 'pessoa_id', 'pessoa_id')
            ->ofMany(['consulta_cnpj_id' => 'max'], fn ($query) => $query->where('ativo', 1));
    }

    /** Pessoa::getStatusAtual (legado): o status vale enquanto a data da consulta não passou; depois expira. */
    public function cnpjStatus(): int
    {
        $review = $this->currentCnpjReview;

        if (! $review) {
            return ConsultaCnpj::NONE;
        }

        return $review->data_consulta->gte(today()) ? (int) $review->status : ConsultaCnpj::EXPIRED;
    }
}
