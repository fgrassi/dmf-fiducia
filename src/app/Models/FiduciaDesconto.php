<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Desconto de cliente Fiducia (fiducia_desconto): por container ('c') ou por processo ('p'). */
class FiduciaDesconto extends Model
{
    public const TIPOS = [
        'c' => 'CONTAINER',
        'p' => 'PROCESSO',
    ];

    protected $table = 'fiducia_desconto';

    protected $primaryKey = 'fiducia_desconto_id';

    public $timestamps = false;

    protected $guarded = [];

    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id', 'pessoa_id');
    }

    public function moeda(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'moeda_id', 'id');
    }
}
