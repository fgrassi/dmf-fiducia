<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Seguradora (cia_seguro). */
class CiaSeguro extends Model
{
    protected $table = 'cia_seguro';

    public $timestamps = false;

    protected $guarded = [];
}
