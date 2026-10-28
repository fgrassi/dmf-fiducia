<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Pessoa extends Model
{
    protected $table = 'pessoa';

    protected $primaryKey = 'pessoa_id';

    public $timestamps = false;

    /** Contatos da empresa (usuario via pessoa_usuario). */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pessoa_usuario', 'pessoa_id', 'usuario_id', 'pessoa_id', 'usuario_id');
    }
}
