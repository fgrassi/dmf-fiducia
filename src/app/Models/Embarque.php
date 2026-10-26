<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Embarque extends Model
{
    protected $table = 'embarque';

    protected $primaryKey = 'embarque_id';

    public $timestamps = false;

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'cliente_id', 'pessoa_id');
    }
}
