<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipo de container / equipamento (tipo_container). */
class TipoContainer extends Model
{
    public const TIPOS = ['20', '40', 'outro', 'lcl', 'aereo', 'roro', 'tank'];

    public const TIPOS_EMBARQUE = [
        'm' => 'Marítimo',
        'a' => 'Aéreo',
    ];

    protected $table = 'tipo_container';

    protected $primaryKey = 'tipo_container_id';

    public $timestamps = false;

    protected $guarded = [];
}
