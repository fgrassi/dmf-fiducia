<?php

namespace App\Models;

use App\Enums\Cobranca\ChargeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinCharges extends Model
{
    protected $table = 'fin_charges';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => ChargeStatus::class,
            'created_at' => 'date',
            'due_date' => 'date',
            'payment_date' => 'date',
        ];
    }

    public function escritorio(): BelongsTo
    {
        return $this->belongsTo(Escritorio::class, 'escritorio_id', 'escritorio_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(FinPaymentReceipt::class, 'ref_charge', 'id');
    }

    public function boletoPayments(): HasMany
    {
        return $this->hasMany(FinPaymentBoleto::class, 'ref_charge', 'id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FinChargesFiles::class, 'ref_charge', 'id');
    }

    public function nfFiles(): HasMany
    {
        return $this->hasMany(FinChargesArquivoNota::class, 'cobranca_id', 'id');
    }

    public function nfses(): HasMany
    {
        return $this->hasMany(FinChargesNfse::class, 'ref_charge', 'id');
    }

    public function customRates(): HasMany
    {
        return $this->hasMany(FinCustomCurrencyRate::class, 'ref_charge', 'id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FinChargesLog::class, 'ref_charge', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(FinChargesTypes::class, 'ref_type', 'id');
    }

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'ref_booking', 'embarque_id');
    }

    public function bl(): BelongsTo
    {
        return $this->belongsTo(Bl::class, 'ref_bl', 'bl_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinChargesItems::class, 'ref_charge', 'id');
    }

    public function latestNfse(): HasOne
    {
        return $this->hasOne(FinChargesNfse::class, 'ref_charge', 'id')->ofMany('fin_charges_nfse_id', 'max');
    }

    /** setEmailsEnvioFromArray: e-mails do cliente escolhidos na baixa/emissão (minúsculos, sem repetição). */
    public function selectRecipients(array $emails): void
    {
        $this->emails_envio_json = json_encode(collect($emails)->map(fn ($m) => strtolower(trim((string) $m)))->filter()->unique()->values()->all());
    }

    /** resumoEmailsDestinoLog do legado. */
    public static function recipientsLogSummary(?array $emails): string
    {
        return match (true) {
            $emails === null => '',
            $emails === [] => ' E-mails cliente: nenhum selecionado.',
            default => ' E-mails cliente ('.count($emails).'): '.implode(', ', $emails),
        };
    }

    /** Semáforo de idade (FinCharges::getStatusColor): success < 3 dias ou baixada, warning 3–7, danger > 7. */
    public function agingColor(): string
    {
        $days = (int) round(abs(now()->startOfDay()->diffInDays($this->created_at->startOfDay())));

        return match (true) {
            $days < 3 || $this->status->isSettled() => 'success',
            $days <= 7 => 'warning',
            default => 'danger',
        };
    }
}
