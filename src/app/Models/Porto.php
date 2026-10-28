<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Porto extends Model
{
    protected $table = 'porto';

    protected $primaryKey = 'porto_id';

    public $timestamps = false;

    protected $guarded = [];
}
