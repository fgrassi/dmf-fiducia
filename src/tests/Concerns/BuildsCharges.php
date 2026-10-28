<?php

namespace Tests\Concerns;

use App\Models\FinCharges;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Fixtures de cobrança no banco legado em memória (usar junto com LegacySchema). */
trait BuildsCharges
{
    protected function user(string $credentials = 'financial'): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);

        return User::find($id);
    }

    /** IC aberta de uma reserva aérea (recálculo sempre permitido), sem cliente (IOF incide). */
    protected function charge(array $attributes = [], array $booking = []): FinCharges
    {
        $embarque = $booking['embarque_id'] ?? 10;
        DB::table('embarque')->insertOrIgnore(['embarque_id' => $embarque, 'booking' => "BKG{$embarque}", 'impor_expor' => 'e', 'tipo_embarque' => 'a', ...$booking]);
        $id = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => $embarque, 'status' => 0, 'created_at' => '2026-10-20', ...$attributes]);

        return FinCharges::find($id);
    }

    /** Item da IC com a despesa da reserva (venda $sale em $currency); $stale é o valor gravado antes do recálculo. */
    protected function item(FinCharges $charge, float $sale, int $currency = 1, int $expense = 500, float $stale = 1.0): int
    {
        DB::table('fin_expenses')->insertOrIgnore(['id' => $expense, 'name' => "TAXA {$expense}", 'ref_unity' => 5]);
        $value = DB::table('fin_expenses_values')->insertGetId([
            'ref_expense' => $expense, 'ref_embarque' => $charge->ref_booking, 'selling_rate' => $sale, 'ref_currency_sr' => $currency, 'billing' => 'p',
        ]);

        return DB::table('fin_charges_items')->insertGetId([
            'ref_charge' => $charge->id, 'ref_expense' => $value, 'unitary_value' => $stale, 'ref_currency' => $currency,
            'current_rate_value' => 1, 'item_value' => $stale, 'created_at' => '2026-10-20',
        ]);
    }

    /** USD (id 2) a R$ 5,00 com 2% de percentual padrão na data de hoje: cotação 5,1. */
    protected function usdRate(float $rate = 5.0, float $tax = 2.0): void
    {
        DB::table('cmn_currencies')->insertOrIgnore([['id' => 1, 'name' => 'BRL', 'symbol' => 'R$'], ['id' => 2, 'name' => 'USD', 'symbol' => '$']]);
        DB::table('fin_currencies_rates')->insert(['id' => 1, 'name' => 'USD', 'ref_currency' => 2]);
        DB::table('fin_default_rates')->insert(['ref_currency_rate' => 1, 'default_tax' => $tax]);
        DB::table('fin_currencies_rates_values')->insert(['ref_currency_rate' => 1, 'rate' => $rate, 'created_by' => 1, 'rate_date' => now()->toDateString()]);
    }

    protected function itemValues(FinCharges $charge): array
    {
        return DB::table('fin_charges_items')->where('ref_charge', $charge->id)->orderBy('id')->pluck('item_value')->map(fn ($v) => (float) $v)->all();
    }
}
