<?php

namespace App\Actions\CurrencyRates;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lança o valor de uma cotação numa data (currencyRateValues, currencyRateArmadorValues e currencyRateArmadorPaymentValues, legado).
 * Já existe valor na data: só troca o valor e grava updated_by/updated_at; senão cria. Só a cotação base
 * (`currency`) propaga o valor para as cotações vinculadas (atualizaCotacoesVinculadas).
 */
class SaveRateValue
{
    public const CURRENCY = 'currency';

    public const CARRIER = 'carrier';

    public const CARRIER_PAYMENT = 'carrier_payment';

    /** tipo => [tabela do histórico, coluna da cotação] */
    private const VALUES = [
        self::CURRENCY => ['fin_currencies_rates_values', 'ref_currency_rate'],
        self::CARRIER => ['fin_currencies_rates_armador_values', 'ref_currency_rate_armador'],
        self::CARRIER_PAYMENT => ['fin_currencies_rates_armador_payment_values', 'ref_currency_rate_armador'],
    ];

    public function __invoke(string $kind, int $rateId, float $rate, ?string $date = null, ?int $userId = null): void
    {
        $date ??= now()->toDateString();

        if ($kind !== self::CURRENCY && $date > now()->toDateString()) {
            throw new InvalidArgumentException('A data não pode ser maior que hoje.');
        }

        DB::transaction(function () use ($kind, $rateId, $rate, $date, $userId) {
            $this->upsert($kind, $rateId, $rate, $date, $userId);

            if ($kind === self::CURRENCY) {
                $this->updateLinkedRates($rateId, $rate, $date, $userId);
            }
        });
    }

    /** Cotações cujo `ref_currency_rate` é esta (valor × (1 + fator/100)) e pagamentos de armador ligados a ela. */
    private function updateLinkedRates(int $rateId, float $rate, string $date, ?int $userId): void
    {
        foreach (DB::table('fin_currencies_rates')->where('ref_currency_rate', $rateId)->get(['id', 'fator']) as $linked) {
            $this->upsertLinked(self::CURRENCY, $linked->id, (1 + $linked->fator / 100) * $rate, $date, $userId);
        }

        foreach (DB::table('fin_currencies_rates_armador_payment')->where('fin_currencies_rates_id', $rateId)->get(['id', 'fator']) as $linked) {
            $this->upsertLinked(self::CARRIER_PAYMENT, $linked->id, (1 + $linked->fator / 100) * $rate, $date, $userId);
        }
    }

    /** Propagação: valor já existente na data é só trocado (sem updated_by/at), como no legado. */
    private function upsertLinked(string $kind, int $rateId, float $rate, string $date, ?int $userId): void
    {
        [$table, $key] = self::VALUES[$kind];
        $existing = DB::table($table)->where($key, $rateId)->where('rate_date', $date);

        if ($existing->exists()) {
            $existing->update(['rate' => $rate]);
        } else {
            $this->insert($kind, $rateId, $rate, $date, $userId);
        }
    }

    private function upsert(string $kind, int $rateId, float $rate, string $date, ?int $userId): void
    {
        [$table, $key] = self::VALUES[$kind];
        $existing = DB::table($table)->where($key, $rateId)->where('rate_date', $date);

        if ($existing->exists()) {
            $existing->update(['rate' => $rate, 'updated_by' => $userId, 'updated_at' => now()->toDateString()]);
        } else {
            $this->insert($kind, $rateId, $rate, $date, $userId);
        }
    }

    private function insert(string $kind, int $rateId, float $rate, string $date, ?int $userId): void
    {
        [$table, $key] = self::VALUES[$kind];

        DB::table($table)->insert([
            $key => $rateId,
            'rate' => $rate,
            'rate_date' => $date,
            'created_at' => now()->toDateString(),
            'created_by' => $userId,
        ]);
    }
}
