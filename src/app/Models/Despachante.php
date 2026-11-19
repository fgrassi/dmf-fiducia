<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Despachante extends Model
{
    protected $table = 'despachante';

    protected $primaryKey = 'despachante_id';

    public $timestamps = false;

    protected $guarded = [];
}
