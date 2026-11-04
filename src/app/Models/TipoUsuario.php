<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

class TipoUsuario extends Model
{
    use HasReferences;

    protected $table = 'tipo_usuario';

    protected $primaryKey = 'tipo_usuario_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'pessoa_usuario_tipo_usuario' => 'tipo_usuario_id',
    ];
}
