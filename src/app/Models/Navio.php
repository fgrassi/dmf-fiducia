<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Navio (navio): usado como navio de origem e de destino do embarque. */
class Navio extends Model
{
    protected $table = 'navio';

    protected $primaryKey = 'navio_id';

    public $timestamps = false;

    protected $guarded = [];

    /** Regra do legado: só embarque (navio ou navio de destino) bloqueia a exclusão. */
    public function isReferenced(): bool
    {
        $id = $this->getKey();

        return DB::table('embarque')->where(fn ($query) => $query->where('navio_id', $id)->orWhere('navio_id_destino', $id))->exists();
    }
}
