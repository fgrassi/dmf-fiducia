<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCreditNotes extends Model
{
    protected $table = 'fin_credit_notes';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'ref_agente', 'pessoa_id');
    }
}
