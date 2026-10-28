<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracoes extends Model
{
    protected $table = 'configuracoes';

    protected $primaryKey = 'configuracoes_id';

    public $timestamps = false;

    protected $guarded = [];
}
