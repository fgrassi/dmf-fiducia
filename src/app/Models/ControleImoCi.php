<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Controle do IMO da reserva de importação: envio da documentação ao armador e a confirmação de recebimento. */
class ControleImoCi extends Model
{
    protected $table = 'controle_imo_ci';

    protected $primaryKey = 'controle_imo_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['envio_imo_armador' => 'date', 'confirmacao_imo_armador' => 'date'];
}
