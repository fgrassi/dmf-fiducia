<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** invoice_impo / invoice_expo: o armador exige invoice do MBL para liberar a cobrança (InvoiceGate). */
class Armador extends Model
{
    protected $table = 'armador';

    protected $primaryKey = 'armador_id';

    public $timestamps = false;

    protected $guarded = [];

    public function embarques(): HasMany
    {
        return $this->hasMany(Embarque::class, 'armador_id', 'armador_id');
    }

    public function ports(): HasMany
    {
        return $this->hasMany(CmnArmadorPorto::class, 'ref_armador', 'armador_id');
    }

    public function banks(): HasMany
    {
        return $this->hasMany(FinArmadorBanks::class, 'ref_armador', 'armador_id');
    }

    public function guaranteeDeposits(): HasMany
    {
        return $this->hasMany(DepositoGarantia::class, 'ref_armador', 'armador_id');
    }

    public function invoiceControls(): HasMany
    {
        return $this->hasMany(ControleInvoiceMaster::class, 'armador_id', 'armador_id');
    }
}
