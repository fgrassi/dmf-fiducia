<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Companhia aérea (cia_aerea): usada nas reservas aéreas e nas cotações IMPO aéreo. */
class CiaAerea extends Model
{
    protected $table = 'cia_aerea';

    public $timestamps = false;

    protected $guarded = [];
}
