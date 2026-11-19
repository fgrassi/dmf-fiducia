<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro do envio do embarque ao agente (AMS) e da confirmação de recebimento, com o arquivo da confirmação. */
abstract class AmsRegistration extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data_envio' => 'datetime', 'data_confirmacao' => 'datetime'];
}
