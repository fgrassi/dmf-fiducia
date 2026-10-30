<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** Capa de contabilidade de um processo (módulo capasContabilidade do legado). */
class CapaProcesso extends Model
{
    protected $table = 'capa_processo';

    protected $primaryKey = 'capa_process_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'payment_date' => 'date',
        ];
    }

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'embarque_id', 'embarque_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'moeda_id', 'id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(CapaProcessoArquivo::class, 'capa_processo_arquivo_id', 'capa_processo_arquivo_id');
    }

    /** Soma dos itens de receita e de despesa na mesma consulta (os totais abaixo usam; sem isso, 1 consulta por capa). */
    public function scopeWithItemTotals(Builder $query): void
    {
        $query->select('capa_processo.*')->addSelect([
            'receipt_items_total' => DB::table('capa_processo_receita_item as i')->selectRaw('COALESCE(SUM(i.valor), 0)')->whereColumn('i.capa_processo_id', 'capa_processo.capa_process_id'),
            'expense_items_total' => DB::table('capa_processo_despesa_item as i')->selectRaw('COALESCE(SUM(i.valor), 0)')->whereColumn('i.capa_processo_id', 'capa_processo.capa_process_id'),
        ]);
    }

    /** CapaProcesso::getTotalReceitas (legado): itens + IOF, juros, taxa de boleto e multa (só se positivos) - desconto. */
    public function receiptsTotal(): float
    {
        return $this->itemsTotal('receipt_items_total', 'capa_processo_receita_item')
            + $this->positive('valor_iof') - $this->positive('desconto') + $this->positive('valor_juros')
            + $this->positive('valor_tax_boleto') + $this->positive('valor_multa');
    }

    /** CapaProcesso::getTotalDespesas (legado): itens + desconto Fiducia + IOF da compra + custo financeiro em módulo. */
    public function expensesTotal(): float
    {
        return $this->itemsTotal('expense_items_total', 'capa_processo_despesa_item')
            + (float) $this->valor_fiducia + (float) $this->valor_iof_compra + abs((float) $this->custo_financeiro);
    }

    /** CapaProcesso::getResultado (legado): resultado em R$ da capa. */
    public function result(): float
    {
        return $this->receiptsTotal() - $this->expensesTotal();
    }

    /** CapaProcesso::getTaxa (legado): (receitas + comissões) no exterior convertidas pela cotação. */
    public function rate(): float
    {
        return ((float) $this->receitas + (float) $this->comissoes) * (float) $this->valor_cotacao;
    }

    /** Coluna "Resultado (Capa)" da lista. */
    public function coverResult(): float
    {
        return $this->result() + $this->rate();
    }

    /** Embarque::getResultadoEmbarque (legado): resultado de todas as capas da reserva. Coluna "Resultado (Processo)". */
    public function processResult(): float
    {
        return self::withItemTotals()->where('embarque_id', $this->embarque_id)->get()->sum(fn (self $cover) => $cover->coverResult());
    }

    private function itemsTotal(string $alias, string $table): float
    {
        return (float) ($this->attributes[$alias] ?? DB::table($table)->where('capa_processo_id', $this->getKey())->sum('valor'));
    }

    private function positive(string $column): float
    {
        return max((float) $this->{$column}, 0.0);
    }
}
