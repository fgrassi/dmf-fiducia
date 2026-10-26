<?php

namespace App\Actions\Cobranca\Rates;

use App\Models\FinCharges;
use App\Models\FinCustomCurrencyRate;
use DomainException;
use Illuminate\Support\Facades\DB;

/** ListaCustom act=2 (legado): cadastra uma cotação negociada para uma moeda dos itens da cobrança. */
class SaveCustomRate
{
    /** Moedas elegíveis: as dos itens da cobrança, exceto BRL (FinCustomCurrencyRateForm::buildCurrencyQuery). */
    public static function currencyOptions(FinCharges $charge): array
    {
        return DB::table('fin_charges_items as i')->join('cmn_currencies as c', 'c.id', '=', 'i.ref_currency')
            ->where('i.ref_charge', $charge->id)->where('i.ref_currency', '<>', 1)
            ->orderBy('c.name')->distinct()->pluck('c.name', 'c.id')->all();
    }

    /** @param  string  $rate  digitado em pt-BR ("5,1234" / "1.234,5678") */
    public function __invoke(FinCharges $charge, int $currencyId, string $rate, string $note, ?int $userId): FinCustomCurrencyRate
    {
        if (! array_key_exists($currencyId, self::currencyOptions($charge))) {
            throw new DomainException('Moeda inválida: a cobrança não possui itens nessa moeda.');
        }

        $value = str_replace(',', '.', str_replace('.', '', trim($rate)));

        if (! is_numeric($value)) {
            throw new DomainException('Valor negociado inválido.');
        }

        if (trim($note) === '') {
            throw new DomainException('Todos os campos precisam estar preenchidos!');
        }

        return FinCustomCurrencyRate::forceCreate([
            'ref_charge' => $charge->id,
            'ref_currency' => $currencyId,
            'custom_rate' => $value,
            'note' => $note,
            'created_at' => now()->toDateString(),
            'created_by' => $userId,
        ]);
    }
}
