<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public const OPERATIONS = ['i' => 'Importação', 'e' => 'Exportação'];

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

    public function bls(): HasMany
    {
        return $this->hasMany(Bl::class, 'embarque_id', 'embarque_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinExpensesValues::class, 'ref_embarque', 'embarque_id');
    }

    /** Embarque::getRefArmadorParaPagamentoId (legado): o coloader, se informado, senão o armador da reserva. */
    public function paymentCarrierId(): ?int
    {
        return (int) $this->coloader_id > 0 ? (int) $this->coloader_id : ($this->armador_id ? (int) $this->armador_id : null);
    }
}
