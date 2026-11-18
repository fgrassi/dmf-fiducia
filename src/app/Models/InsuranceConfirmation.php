<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dados do seguro de uma reserva de seguro (tipo_embarque 's'). `control` 1 = cobrança habilitada ("Permitir cobrança"). */
class InsuranceConfirmation extends Model
{
    public const CHARGE_ALLOWED = 1;

    protected $table = 'insurance_confirmation';

    public $timestamps = false;

    protected $guarded = [];

    public function ciaSeguro(): BelongsTo
    {
        return $this->belongsTo(CiaSeguro::class, 'ref_cia_seguro');
    }

    public function invoiceCurrency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency_fatura');
    }

    public function freightCurrency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency_frete');
    }

    public function expensesCurrency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency_despesa');
    }

    public function isChargeAllowed(): bool
    {
        return (int) $this->control === self::CHARGE_ALLOWED;
    }
}
