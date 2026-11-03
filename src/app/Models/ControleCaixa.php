<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Caixa de arquivo de processos encerrados (controle_caixa no legado). */
class ControleCaixa extends Model
{
    public const OPEN = 'a';

    public const CLOSED = 'f';

    /** ControleCaixa::getStatusNome (legado). */
    public const STATUSES = ['a' => 'Aberta', 'f' => 'Fechada', 'd' => 'Descartada'];

    /** ControleCaixa::getTipoNome (legado). */
    public const TYPES = ['i' => 'Importação', 'e' => 'Exportação', 'd' => 'Diversos'];

    protected $table = 'controle_caixa';

    protected $primaryKey = 'controle_caixa_id';

    public $timestamps = false;

    protected $guarded = [];

    public function bookings(): HasMany
    {
        return $this->hasMany(ControleCaixaEmbarque::class, 'controle_caixa_id', 'controle_caixa_id');
    }

    /** ControleCaixa::getLimite (legado): 110 processos nas caixas "Diversos", 50 nas demais. */
    public function limit(): int
    {
        return $this->tipo_caixa === 'd' ? 110 : 50;
    }
}
