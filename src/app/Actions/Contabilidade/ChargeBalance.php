<?php

namespace App\Actions\Contabilidade;

use Illuminate\Database\Query\Builder;

/**
 * Saldo de uma cobrança (IC) a receber numa data, como o legado calcula nos relatórios de contabilidade (sem histórico de
 * parcelas: o recebido é o valor atual, contado só se a baixa é até a data).
 */
class ChargeBalance
{
    /** Baixa bancária (1), boleto (2) e manual (3). */
    private const SETTLED = [1, 2, 3];

    /** Cobranças que podem ter tido saldo na data: em aberto, baixadas depois dela ou com diferença entre total e recebido. */
    public static function candidates(Builder $query, string $date): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('fc.payment_date')
            ->orWhere('fc.payment_date', '0000-00-00')
            ->orWhere('fc.payment_date', '>', $date)
            ->orWhere('fc.status', 0)
            ->orWhereRaw('(COALESCE(fc.total_value, 0) - COALESCE(fc.discount, 0) - COALESCE(fc.received_value, 0)) > 0.01'));
    }

    /** @param  object  $charge  com total_value, discount, received_value, payment_date, due_date e status */
    public static function openAt(object $charge, string $date): float
    {
        $net = round((float) $charge->total_value - (float) $charge->discount, 2);

        if ($net <= 0.01) {
            return 0.0;
        }

        $status = (int) $charge->status;
        $paidOn = NfseInvoice::date($charge->payment_date) ?? ($status === 3 ? NfseInvoice::date($charge->due_date) : null);
        $received = (float) $charge->received_value;

        if ($status === 3 && $received <= 0.01) {
            $received = $net;
        }

        $receivedUntil = match (true) {
            $paidOn !== null && $paidOn <= $date => $received,
            in_array($status, self::SETTLED, true) && $paidOn === null => 0.0, // quitada sem data confiável: conservador para AR
            $status === 0 && $received > 0.01 => $received, // parcial em aberto sem data: o recebido atual
            default => 0.0,
        };

        return round($net - $receivedUntil, 2);
    }
}
