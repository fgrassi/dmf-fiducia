<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** consulta_cnpj (legado): cada mudança de status da empresa desativa a anterior (ativo) e vale até data_consulta. */
class ConsultaCnpj extends Model
{
    public const RESTRICTED = 0;

    public const OK = 1;

    public const REVIEW = 2;

    /** Status calculado (nunca gravado): a validade da última consulta passou. */
    public const EXPIRED = 3;

    /** Status calculado: a empresa nunca foi consultada. */
    public const NONE = -1;

    /** ConsultaCnpjForm (legado): opções gravadas pela tela. */
    public const CHOICES = [self::RESTRICTED => 'RESTRIÇÃO', self::OK => 'OK', self::REVIEW => 'REVISAR'];

    /** Pessoa::getStatusNome (legado). */
    public const LABELS = [
        self::RESTRICTED => 'Não Aprovado',
        self::OK => 'Sem Restrições',
        self::REVIEW => 'Aprovado c/ Restrições',
        self::EXPIRED => 'Consulta Expirada',
        self::NONE => 'Consulta Expirada',
    ];

    /** Pessoa::getStatusCor (legado). */
    public const COLORS = [
        self::RESTRICTED => 'danger',
        self::OK => 'success',
        self::REVIEW => 'warning',
        self::EXPIRED => 'info',
        self::NONE => 'gray',
    ];

    protected $table = 'consulta_cnpj';

    protected $primaryKey = 'consulta_cnpj_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data_consulta' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id', 'pessoa_id');
    }
}
