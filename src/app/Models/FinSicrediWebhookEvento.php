<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Evento recebido do webhook de cobrança do Sicredi (liquidação/estorno). */
class FinSicrediWebhookEvento extends Model
{
    protected $table = 'fin_sicredi_webhook_evento';

    public $timestamps = false;
}
