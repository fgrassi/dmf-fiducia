<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinPaymentReceipt extends Model
{
    protected $table = 'fin_payment_receipt';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
        ];
    }
}
