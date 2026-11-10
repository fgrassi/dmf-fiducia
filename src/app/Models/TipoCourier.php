<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipo de courier (tipo_courier no legado). */
class TipoCourier extends Model
{
    protected $table = 'tipo_courier';

    protected $primaryKey = 'tipo_courier_id';

    public $timestamps = false;

    protected $guarded = [];
}
