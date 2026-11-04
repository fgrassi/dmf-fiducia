<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

class CentroCusto extends Model
{
    use HasReferences;

    protected $table = 'centro_custo';

    protected $primaryKey = 'centro_custo_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'despesa' => 'centro_custo_id',
    ];
}
