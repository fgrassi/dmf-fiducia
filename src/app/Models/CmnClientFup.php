<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Follow up de atividades de um cliente (cmn_client_fup): relato do vendedor, com a avaliação do cliente no momento. */
class CmnClientFup extends Model
{
    /** CmnClientFup::COTACAO_EXPO_FINALIZADA_DESCR_LIKE (legado): follow ups gerados ao finalizar uma cotação de exportação. */
    public const QUOTATION_FINISHED_LIKE = 'Cotação % finalizada:%';

    public const TYPES = ['i' => 'Importação', 'e' => 'Exportação', 'f' => 'Fiducia'];

    public const VOLUMES = [
        0 => 'N/A',
        1 => 'Sem embarques',
        2 => 'Poucos embarques no ano',
        3 => 'Embarques com regularidade',
        4 => 'Embarques com volumes',
    ];

    public const OPENINGS = [
        0 => 'N/A',
        1 => 'Nenhuma abertura ou Setor de comex em outra região',
        2 => 'Pouca abertura',
        3 => 'Boa abertura',
        4 => 'Fecham conosco',
    ];

    protected $table = 'cmn_client_fup';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'date', 'data_ocorrencia' => 'date'];
    }

    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'ref_pessoa', 'pessoa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ref_user', 'usuario_id');
    }
}
