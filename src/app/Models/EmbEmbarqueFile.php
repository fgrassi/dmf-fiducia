<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Arquivo anexado à reserva (emb_embarque_files); o tipo 54 é o comprovante de pagamento do armador. */
class EmbEmbarqueFile extends Model
{
    public const PAYMENT_RECEIPT = 54;

    protected $table = 'emb_embarque_files';

    protected $primaryKey = 'id';

    public $timestamps = false;

    public function file(): BelongsTo
    {
        return $this->belongsTo(CmnFile::class, 'ref_file', 'id');
    }
}
