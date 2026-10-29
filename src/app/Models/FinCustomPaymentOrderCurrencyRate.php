<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cotação negociada da ordem de pagamento: vale para a moeda toda ou para uma despesa da reserva. */
class FinCustomPaymentOrderCurrencyRate extends Model
{
    protected $table = 'fin_custom_payment_order_currency_rate';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FinPaymentOrder::class, 'ref_payment_order', 'id');
    }
}
