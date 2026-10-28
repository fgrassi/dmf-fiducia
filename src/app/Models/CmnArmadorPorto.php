<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmnArmadorPorto extends Model
{
    protected $table = 'cmn_armador_porto';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function armador(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'ref_armador', 'armador_id');
    }

    public function porto(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'ref_porto', 'porto_id');
    }

    public function matrix(): HasMany
    {
        return $this->hasMany(FinExpensesMatrix::class, 'ref_armador_porto');
    }
}
