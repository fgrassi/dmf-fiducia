<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem de rastreamento (tracking) da reserva (transborda). */
class Transborda extends Model
{
    /** Categorias de frase lançadas pelo sistema (notas e afins): não se edita nem se exclui à mão. */
    public const SYSTEM_CATEGORIES = [10, 11];

    protected $table = 'transborda';

    protected $primaryKey = 'transborda_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data_reg' => 'date'];

    public function phrase(): BelongsTo
    {
        return $this->belongsTo(FraseTransborda::class, 'ref_frase_transborda', 'id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id', 'usuario_id');
    }

    public function isSystemGenerated(): bool
    {
        return in_array((int) $this->phrase?->categoria_id, self::SYSTEM_CATEGORIES, true);
    }
}
