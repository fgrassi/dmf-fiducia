<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrupoDespesa extends Model
{
    /** GrupoDespesaForm (legado): centro de custo do grupo. */
    public const COST_CENTERS = [
        'f' => 'FIN',
        'i' => 'IMP',
        'e' => 'EXP',
        'd' => 'DOCS',
    ];

    protected $table = 'grupo_despesa';

    protected $primaryKey = 'grupo_despesa_id';

    public $timestamps = false;

    protected $guarded = [];

    public function expenses(): HasMany
    {
        return $this->hasMany(GrupoDespesaDespesa::class, 'grupo_despesa_id');
    }
}
