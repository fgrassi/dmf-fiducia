<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Porto em que o despachante atua (despachante_porto), dono da matriz de despesas. */
class DespachantePorto extends Model
{
    protected $table = 'despachante_porto';

    public $timestamps = false;

    protected $guarded = [];

    public function despachante(): BelongsTo
    {
        return $this->belongsTo(Despachante::class, 'id_despachante', 'despachante_id');
    }

    public function porto(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'id_porto', 'porto_id');
    }

    public function matrix(): HasMany
    {
        return $this->hasMany(MatrizDespesaDespachante::class, 'ref_despachante_porto');
    }
}
