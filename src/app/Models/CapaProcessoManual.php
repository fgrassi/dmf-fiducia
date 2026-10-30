<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Capa de contabilidade manual (módulo capasContabilidadeManual do legado): uma despesa lançada à mão, com compra e venda. */
class CapaProcessoManual extends Model
{
    protected $table = 'capa_processo_manual';

    protected $primaryKey = 'capa_processo_manual_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'payment_date' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'cliente_id', 'pessoa_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinExpenses::class, 'ref_expense', 'id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'moeda_id', 'id');
    }

    public function buyingCurrency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency_br', 'id');
    }

    public function sellingCurrency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency_sr', 'id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(CapaProcessoArquivo::class, 'capa_processo_arquivo_id', 'capa_processo_arquivo_id');
    }

    /**
     * CapaProcessoManual::getResultado (legado): venda - compra em R$, convertendo pela cotação o lado em moeda estrangeira.
     * Com um só lado em R$ converte sempre a venda; o caso "compra em moeda estrangeira e venda em R$" fica assim, como no
     * legado, embora pareça trocado (1 capa em 359).
     */
    public function result(): float
    {
        $cost = (float) $this->buying_rate;
        $revenue = (float) $this->selling_rate;
        $rate = (float) $this->valor_cotacao;
        $buysInReais = $this->ref_currency_br == 1;
        $sellsInReais = $this->ref_currency_sr == 1;

        return match (true) {
            $buysInReais && $sellsInReais => $revenue - $cost,
            $buysInReais || $sellsInReais => $revenue * $rate - $cost,
            default => $revenue * $rate - $cost * $rate,
        };
    }

    /** Lucro em USD/EUR convertido pela cotação (a "taxa" da capa manual). */
    public function rate(): float
    {
        return (float) $this->valor_usd_eur * (float) $this->valor_cotacao;
    }
}
