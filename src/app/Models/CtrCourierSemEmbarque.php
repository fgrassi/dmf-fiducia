<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nome ligado a um courier que não pertence a nenhuma reserva (ctr_courier_sem_embarque no legado). */
class CtrCourierSemEmbarque extends Model
{
    protected $table = 'ctr_courier_sem_embarque';

    protected $primaryKey = 'ctr_courier_sem_embarque_id';

    public $timestamps = false;

    protected $guarded = [];
}
