<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

class CategoriaDespesa extends Model
{
    use HasReferences;

    protected $table = 'categoria_despesa';

    protected $primaryKey = 'categoria_despesa_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'despesa' => 'categoria_despesa_id',
        'despesa_vencimento' => 'categoria_despesa_id',
    ];
}
