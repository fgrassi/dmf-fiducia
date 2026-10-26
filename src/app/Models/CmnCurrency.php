<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CmnCurrency extends Model
{
    protected $table = 'cmn_currencies';

    protected $primaryKey = 'id';

    public $timestamps = false;
}
