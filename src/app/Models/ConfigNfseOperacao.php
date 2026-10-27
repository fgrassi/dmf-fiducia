<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Operação / tipo de serviço da NFS-e (config_nfse_operacao): códigos fiscais e descrição por operação (EXPO, IMPO, …). */
class ConfigNfseOperacao extends Model
{
    public const IMPOR_EXPOR = ['i' => 'Import', 'e' => 'Export'];

    protected $table = 'config_nfse_operacao';

    protected $primaryKey = 'config_nfse_operacao_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'iss_retido' => 'boolean',
            'ibs_cbs_habilitado' => 'boolean',
            'pis_cofins_habilitado' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    /** Despesas desta operação geram recibo e NFS-e separados na baixa. */
    public function expenseLinks(): HasMany
    {
        return $this->hasMany(ConfigNfseOperacaoDespesa::class, 'config_nfse_operacao_id');
    }

    /** configuracaoNfse/addDespesa: motivo que impede vincular a despesa, ou null se pode. */
    public function expenseLinkError(int $expenseId): ?string
    {
        if ($this->expenseLinks()->where('fin_expenses_id', $expenseId)->exists()) {
            return 'Despesa já vinculada a esta operação.';
        }

        // findConflitoMesmoModal: uma despesa só pode estar em uma operação ativa do mesmo impor_expor.
        $conflict = isset(self::IMPOR_EXPOR[$this->impor_expor]) ? self::query()
            ->whereKeyNot($this->getKey())
            ->where('impor_expor', $this->impor_expor)
            ->where('ativo', 1)
            ->whereHas('expenseLinks', fn ($q) => $q->where('fin_expenses_id', $expenseId))
            ->first() : null;

        return $conflict ? "Despesa já vinculada à operação \"{$conflict->label()}\" do mesmo modal." : null;
    }

    /** ConfigNfseOperacao::__toString. */
    public function label(): string
    {
        return collect([$this->codigo_operacao, $this->nome])->filter()->implode(' — ') ?: 'Operação NFSe';
    }
}
