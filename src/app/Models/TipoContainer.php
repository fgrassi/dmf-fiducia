<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoContainer extends Model
{
    protected $table = 'tipo_container';

    protected $primaryKey = 'tipo_container_id';

    public $timestamps = false;

    protected $guarded = [];
}
