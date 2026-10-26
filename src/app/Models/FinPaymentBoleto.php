<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinPaymentBoleto extends Model
{
    protected $table = 'fin_payment_boleto';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'due_to' => 'date',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(CmnFile::class, 'ref_file', 'id');
    }
}
