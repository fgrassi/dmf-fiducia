<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendedor extends Model
{
    protected $table = 'vendedor';

    protected $primaryKey = 'vendedor_id';

    public $timestamps = false;

    protected $guarded = [];
}
