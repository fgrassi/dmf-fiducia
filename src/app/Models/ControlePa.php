<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * controle_pa (legado): acompanha a Freight Invoice de uma reserva prepaid abroad. A DMF envia a FI ao agente, o armador
 * devolve a FI dele, a DMF paga e o armador manda o recibo; cada etapa só abre depois da anterior.
 */
class ControlePa extends Model
{
    public const DATE_SENT_TO_AGENT = 'data_envio_fi_agente';

    public const DATE_CARRIER_PAYMENT = 'data_pgto_armador';

    public const DATE_CARRIER_RECEIPT = 'data_recibo_armador';

    /** ControlePa::getStatusFlagFiDmf (legado). */
    public const DMF_STATUSES = [0 => 'Não enviado', 1 => 'Enviado', 2 => 'Embarque Confirmado'];

    /** ControlePa::getStatusFlagFiArmador (legado). */
    public const CARRIER_STATUSES = [0 => 'Não enviado', 1 => 'Enviado'];

    protected $table = 'controle_pa';

    protected $primaryKey = 'controle_pa_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            self::DATE_SENT_TO_AGENT => 'date',
            self::DATE_CARRIER_PAYMENT => 'date',
            self::DATE_CARRIER_RECEIPT => 'date',
        ];
    }

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'embarque_id', 'embarque_id');
    }

    /** listAberto (legado): falta alguma etapa. O filtro de reserva (não cancelada, ETD) fica na tela. */
    public function scopePending(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('flag_fi_dmf', 0)
            ->orWhereNull(self::DATE_SENT_TO_AGENT)
            ->orWhere('flag_fi_armador', 0)
            ->orWhereNull(self::DATE_CARRIER_PAYMENT)
            ->orWhereNull(self::DATE_CARRIER_RECEIPT));
    }

    /** listFechado (legado): todas as etapas cumpridas. */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('flag_fi_dmf', '<>', 0)
            ->whereNotNull(self::DATE_SENT_TO_AGENT)
            ->where('flag_fi_armador', 1)
            ->whereNotNull(self::DATE_CARRIER_PAYMENT)
            ->whereNotNull(self::DATE_CARRIER_RECEIPT);
    }

    public function dmfStatus(): string
    {
        return self::DMF_STATUSES[(int) $this->flag_fi_dmf] ?? '';
    }

    public function carrierStatus(): string
    {
        return self::CARRIER_STATUSES[(int) $this->flag_fi_armador] ?? '';
    }

    /** showSuccess (legado): a data de envio abre com a FI da DMF enviada, a de pagamento com a FI do armador anexada, a do recibo com o pagamento. */
    public function canSetDate(string $field): bool
    {
        return match ($field) {
            self::DATE_SENT_TO_AGENT => (int) $this->flag_fi_dmf !== 0,
            self::DATE_CARRIER_PAYMENT => (int) $this->flag_fi_armador === 1,
            self::DATE_CARRIER_RECEIPT => filled($this->data_pgto_armador),
            default => false,
        };
    }

    /** O anexo de cada tipo abre na mesma etapa da data seguinte: FI do armador após o envio ao agente, comprovante e recibo como as datas. */
    public function canAttach(int $fileType): bool
    {
        return match ($fileType) {
            EmbEmbarqueFile::CARRIER_INVOICE => filled($this->data_envio_fi_agente),
            EmbEmbarqueFile::PAYMENT_RECEIPT => $this->canSetDate(self::DATE_CARRIER_PAYMENT),
            EmbEmbarqueFile::CARRIER_RECEIPT => $this->canSetDate(self::DATE_CARRIER_RECEIPT),
            default => false,
        };
    }

    /** @return Collection<int, EmbEmbarqueFile> */
    public function filesOfType(int $fileType): Collection
    {
        return EmbEmbarqueFile::with('file')
            ->where('ref_embarque', $this->embarque_id)
            ->where('ref_file_type', $fileType)
            ->get();
    }
}
