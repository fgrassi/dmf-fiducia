<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contato de cliente que sempre recebe cobranças e recibos (fin_contato_cobranca). */
class FinContatoCobranca extends Model
{
    protected $table = 'fin_contato_cobranca';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'ref_pessoa', 'pessoa_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }
}
