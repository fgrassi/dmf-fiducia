<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cliente que não recebe cobrança automática (fin_clients_exception): a IC só sai depois do recálculo. */
class FinClientsException extends Model
{
    protected $table = 'fin_clients_exception';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'ref_client', 'pessoa_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }
}
