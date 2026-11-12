<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Embarque extends Model
{
    /** EmbarqueTable::$finalizado_nomes (legado). */
    public const STATUSES = [
        'n' => 'Aberto',
        'p' => 'Suspenso',
        's' => 'Chegou ao destino',
        'e' => 'Encerrado',
        'c' => 'Cancelado',
        'd' => 'Descartado',
    ];

    /** tipo_embarque: modal da reserva. */
    public const TYPES = [
        'm' => 'Marítimo',
        'a' => 'Aéreo',
        's' => 'Seguro',
        'd' => 'Desembaraço',
        'r' => 'Correio',
        'h' => 'Handling',
    ];

    protected $table = 'embarque';

    protected $primaryKey = 'embarque_id';

    public $timestamps = false;

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'cliente_id', 'pessoa_id');
    }

    public function importador(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id_importador', 'pessoa_id');
    }

    public function armador(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'armador_id', 'armador_id');
    }

    public function controlePa(): HasOne
    {
        return $this->hasOne(ControlePa::class, 'embarque_id', 'embarque_id');
    }

    /** Freight Invoices da DMF geradas para a reserva (arquivos de tipo 24), a mais nova primeiro. */
    public function freightInvoices(): HasMany
    {
        return $this->hasMany(EmbEmbarqueFile::class, 'ref_embarque', 'embarque_id')
            ->where('ref_file_type', EmbEmbarqueFile::DMF_INVOICE)
            ->orderByDesc('id');
    }

    public function bls(): HasMany
    {
        return $this->hasMany(Bl::class, 'embarque_id', 'embarque_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ComissaoEmbarque::class, 'embarque_id', 'embarque_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinExpensesValues::class, 'ref_embarque', 'embarque_id');
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(FinDebitNotes::class, 'ref_embarque', 'embarque_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(FinCreditNotes::class, 'ref_embarque', 'embarque_id');
    }

    /** Embarque::getRefArmadorParaPagamentoId (legado): o coloader, se informado, senão o armador da reserva. */
    public function paymentCarrierId(): ?int
    {
        return (int) $this->coloader_id > 0 ? (int) $this->coloader_id : ($this->armador_id ? (int) $this->armador_id : null);
    }

    /** Embarque::getFatorLclImpo (legado): o maior entre peso e volume da cotação (aéreo: volume ÷ 0,006). */
    public function importFactor(): ?float
    {
        $weight = (float) $this->peso_cotacao;
        $volume = (float) $this->volume_cotacao;

        if ($this->impor_expor !== 'i' || (! $weight && ! $volume)) {
            return null;
        }

        return max($this->tipo_embarque === 'm' ? $volume : $volume / 0.006, $weight);
    }
}
