<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    use HasReferences;

    protected $table = 'cargos';

    protected $primaryKey = 'cargos_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'pessoa_usuario' => 'cargos_id',
    ];
}
