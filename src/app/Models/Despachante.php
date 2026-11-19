<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Despachante extends Model
{
    protected $table = 'despachante';

    protected $primaryKey = 'despachante_id';

    public $timestamps = false;

    protected $guarded = [];

    public function contacts(): HasMany
    {
        return $this->hasMany(DespachanteContato::class, 'ref_despachante', 'despachante_id');
    }

    public function ports(): HasMany
    {
        return $this->hasMany(DespachantePorto::class, 'id_despachante', 'despachante_id');
    }

    /** Em uso: reserva, porto ou empresa/trading vinculada (não pode excluir). */
    public function inUse(): bool
    {
        $id = $this->getKey();

        return DB::table('embarque')->where('despachante_id', $id)->orWhere('despachante_dmf_id', $id)->exists()
            || DB::table('porto')->where('despachante_id', $id)->exists()
            || DB::table('pessoa_despachante')->where('despachante_id', $id)->exists()
            || DB::table('trading_despachante')->where('despachante_id', $id)->exists();
    }
}
