<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Assessoria extends Model
{
    protected $table = 'assessoria';

    protected $primaryKey = 'assessoria_id';

    public $timestamps = false;

    protected $guarded = [];

    public function contacts(): HasMany
    {
        return $this->hasMany(AssessoriaContato::class, 'assessoria_id', 'assessoria_id');
    }

    /** Em uso: reserva ou empresa vinculada (não pode excluir). */
    public function inUse(): bool
    {
        return Embarque::where('assessoria_id', $this->getKey())->exists()
            || DB::table('pessoa_assessoria')->where('assessoria_id', $this->getKey())->exists();
    }
}
