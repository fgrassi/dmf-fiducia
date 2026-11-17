<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Frase pronta do rastreamento: `valor` é o rótulo e `frase`, o texto que preenche a descrição. */
class FraseTransborda extends Model
{
    protected $table = 'frase_transborda';

    public $timestamps = false;
}
