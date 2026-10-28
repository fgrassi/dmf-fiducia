<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinArmadorBanks extends Model
{
    protected $table = 'fin_armador_banks';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Contas que o legado não mostra mais mas não pode excluir (histórico). Fixas no código do banco antigo (decisão D6).
     *
     * @var list<int>
     */
    public const DISABLED_IDS = [126, 166, 182, 165, 193];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(CmnBanks::class, 'ref_bank');
    }

    /** Contas em uso: sem exclusão lógica e fora da lista de desativadas. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at')->whereNotIn($this->getTable().'.id', self::DISABLED_IDS);
    }
}
