<?php

namespace App\Models;

use App\Models\Concerns\HasContainerTime;
use Illuminate\Database\Eloquent\Model;

/** Controle de detention de um container da exportação (fin_detention). */
class FinDetention extends Model
{
    use HasContainerTime;

    protected $table = 'fin_detention';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data_retirada' => 'date', 'data_devolucao' => 'date'];

    public function freeTimeDays(): int
    {
        return (int) $this->embarque->free_time_detention;
    }
}
