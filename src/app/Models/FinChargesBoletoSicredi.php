<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Boleto bancário emitido pela API do Sicredi (não é a fin_payment_boleto, que é a declaração da baixa). */
class FinChargesBoletoSicredi extends Model
{
    protected $table = 'fin_charges_boleto_sicredi';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data_vencimento' => 'date', 'valor' => 'float'];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(FinCharges::class, 'ref_charge', 'id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(CmnFile::class, 'ref_file', 'id');
    }

    /** getEmitidoPorCobranca: o último boleto emitido, com linha digitável. */
    public static function issuedFor(int $chargeId): ?self
    {
        return self::where('ref_charge', $chargeId)->where('status', 'emitido')->where('linha_digitavel', '<>', '')->orderByDesc('id')->first();
    }

    public function isIssued(): bool
    {
        return $this->status === 'emitido' && filled($this->linha_digitavel);
    }

    /** Caminho do PDF no disco tracking_uploads. */
    public function pdfPath(): ?string
    {
        return filled($this->file?->name) ? "boleto/{$this->file->name}.pdf" : null;
    }
}
