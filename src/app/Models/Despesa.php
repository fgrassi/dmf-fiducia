<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Despesa da empresa (contas a pagar; tabela `despesa` do legado). Não confundir com `fin_expenses` (despesas do embarque). */
class Despesa extends Model
{
    public const STATUS_CLOSED = 1;

    public const STATUS_OPEN = 2;

    /** Despesa::getStatusNome (legado). */
    public const STATUSES = [self::STATUS_CLOSED => 'Encerrado', self::STATUS_OPEN => 'Aberto'];

    /** Despesa::getTipoPagamentoNome (legado). */
    public const PAYMENT_TYPES = [1 => 'Transferência Bancária', 2 => 'Dinheiro', 3 => 'Cartão de Crédito', 4 => 'Boleto', 5 => 'Cheque', 6 => 'Pix'];

    /** Despesa::getTipoDespesaNome (legado). */
    public const EXPENSE_TYPES = [1 => 'Ordinárias', 2 => 'Extraordinárias'];

    /** Despesa::getEmpresaNome (legado). */
    public const COMPANIES = ['d' => 'DMF', 'f' => 'Fiducia'];

    protected $table = 'despesa';

    protected $primaryKey = 'despesa_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data_prevista' => 'date', 'data_efetiva' => 'date', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'fornecedor_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoriaDespesa::class, 'categoria_despesa_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'moeda_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(CmnBanks::class, 'banco_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ArquivoDespesa::class, 'despesa_id');
    }
}
