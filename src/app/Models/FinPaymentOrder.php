<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Ordem de pagamento a armador (módulo ordemPagamento do legado). */
class FinPaymentOrder extends Model
{
    public const STATUSES = [0 => 'Aberta', 1 => 'Paga', 2 => 'Cancelada'];

    protected $table = 'fin_payment_orders';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'date',
            'payment_date' => 'date',
        ];
    }

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'ref_embarque', 'embarque_id');
    }

    public function armador(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'ref_armador', 'armador_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinPaymentOrderItem::class, 'ref_payment_order', 'id');
    }

    public function customRates(): HasMany
    {
        return $this->hasMany(FinCustomPaymentOrderCurrencyRate::class, 'ref_payment_order', 'id');
    }

    /** Comprovantes de pagamento do armador anexados à reserva da ordem. */
    public function receipts(): HasMany
    {
        return $this->hasMany(EmbEmbarqueFile::class, 'ref_embarque', 'ref_embarque')->where('ref_file_type', EmbEmbarqueFile::PAYMENT_RECEIPT);
    }

    public function instructions(): HasMany
    {
        return $this->hasMany(InstrucaoPagamento::class, 'fin_payment_order_id', 'id');
    }

    /** FinPaymentOrders::getValorTotal (legado): soma dos itens cuja despesa não está desligada. */
    public function enabledTotal(): float
    {
        return (float) DB::table('fin_payment_order_items as i')
            ->where('i.ref_payment_order', $this->getKey())
            ->whereNotExists(fn ($d) => $d->selectRaw('1')->from('fin_payment_order_items_disabled as x')
                ->whereColumn('x.ref_fin_payment_order', 'i.ref_payment_order')
                ->whereColumn('x.ref_expense', 'i.ref_expense')
                ->where('x.status', 0))
            ->sum('i.item_value');
    }
}
