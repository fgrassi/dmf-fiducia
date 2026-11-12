<?php

namespace App\Models;

/** Confirmação de chegada da reserva (arrival_confirmation); mesma estrutura da de embarque. */
class ArrivalConfirmation extends DepartureConfirmation
{
    protected $table = 'arrival_confirmation';
}
