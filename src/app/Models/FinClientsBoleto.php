<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinClientsBoleto extends Model
{
    protected $table = 'fin_clients_boleto';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'ref_client', 'pessoa_id');
    }
}
