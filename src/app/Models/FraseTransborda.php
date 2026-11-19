<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Frase pronta do rastreamento: `valor` é o rótulo e `frase`, o texto que preenche a descrição. */
class FraseTransborda extends Model
{
    protected $table = 'frase_transborda';

    public $timestamps = false;

    protected $guarded = [];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FraseCategoria::class, 'categoria_id', 'id');
    }
}
