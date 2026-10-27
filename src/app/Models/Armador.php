<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** invoice_impo / invoice_expo: o armador exige invoice do MBL para liberar a cobrança (InvoiceGate). */
class Armador extends Model
{
    protected $table = 'armador';

    protected $primaryKey = 'armador_id';

    public $timestamps = false;
}
