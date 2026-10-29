<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item da ordem de pagamento: uma despesa da reserva (ref_expense = fin_expenses_values.id) por BL. */
class FinPaymentOrderItem extends Model
{
    protected $table = 'fin_payment_order_items';

    protected $primaryKey = 'id';

    public $timestamps = false;

    public function order(): BelongsTo
    {
        return $this->belongsTo(FinPaymentOrder::class, 'ref_payment_order', 'id');
    }
}
