<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcessoManual;
use App\Models\CmnCurrency;

/**
 * Dados do pdfCapaContabilidadeManual (legado): a despesa lançada à mão, compra e venda em R$ e o lucro em USD/EUR.
 *
 * Diferença do legado: mês/ano em branco quando a capa não tem data (o legado imprime JANEIRO/1970).
 */
class ManualCoverSheet
{
    /** @return array<string, string> */
    public function __invoke(CapaProcessoManual $cover): array
    {
        $cover->loadMissing(['client', 'expense', 'buyingCurrency', 'sellingCurrency']);

        $rate = (float) $cover->valor_cotacao;
        $cost = (float) $cover->buying_rate * ($cover->ref_currency_br != 1 ? $rate : 1);
        $revenue = (float) $cover->selling_rate * ($cover->ref_currency_sr != 1 ? $rate : 1);
        $hasProfit = in_array($cover->moeda_usd_eur, [2, 3]);

        return [
            'expense' => (string) $cover->expense?->name,
            'client' => filled($cover->nome_cliente) ? $cover->nome_cliente : (string) $cover->client?->nome,
            'month_year' => $cover->payment_date ? CoverSheet::MONTHS[$cover->payment_date->month].'/'.$cover->payment_date->year : '',
            'number' => (string) $cover->numero,
            'cost' => $this->money($cost),
            'revenue' => $this->money($revenue),
            'difference' => $this->money($cover->result()),
            'foreign_profit' => $hasProfit ? $this->symbol($cover->moeda_usd_eur).' '.$this->number((float) $cover->valor_usd_eur) : 'R$ 0,00',
            'conversion' => $hasProfit ? $this->money($cover->rate()) : 'R$ 0,00',
        ];
    }

    private function symbol(int $currencyId): string
    {
        return (string) CmnCurrency::whereKey($currencyId)->value('symbol');
    }

    private function money(float $value): string
    {
        return 'R$ '.$this->number($value);
    }

    private function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
