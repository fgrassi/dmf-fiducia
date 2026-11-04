<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArquivoDespesa extends Model
{
    protected $table = 'arquivo_despesa';

    protected $primaryKey = 'arquivo_despesa_id';

    public $timestamps = false;

    protected $guarded = [];
}
