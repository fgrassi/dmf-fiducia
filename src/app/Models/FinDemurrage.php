<?php

namespace App\Models;

use App\Models\Concerns\HasContainerTime;
use Illuminate\Database\Eloquent\Model;

/** Controle de demurrage de um container da importação (fin_demurrage). */
class FinDemurrage extends Model
{
    use HasContainerTime;

    protected $table = 'fin_demurrage';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data_retirada' => 'date', 'data_devolucao' => 'date'];

    public function freeTimeDays(): int
    {
        return (int) ($this->free_time ?: $this->embarque->free_time);
    }
}
