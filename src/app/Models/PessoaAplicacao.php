<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PessoaAplicacao extends Model
{
    protected $table = 'pessoa_aplicacao';

    protected $primaryKey = 'pessoa_aplicacao_id';

    public $timestamps = false;

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id', 'pessoa_id');
    }
}
