<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Arquivo anexado à reserva (emb_embarque_files); tipo 24 = Freight Invoice da DMF, 37 = do armador, 54 = comprovante de pagamento do armador, 57 = recibo do armador. */
class EmbEmbarqueFile extends Model
{
    public const DMF_INVOICE = 24;

    public const CARRIER_INVOICE = 37;

    public const PAYMENT_RECEIPT = 54;

    public const CARRIER_RECEIPT = 57;

    protected $table = 'emb_embarque_files';

    protected $primaryKey = 'id';

    public $timestamps = false;

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'ref_embarque', 'embarque_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(CmnFile::class, 'ref_file', 'id');
    }
}
