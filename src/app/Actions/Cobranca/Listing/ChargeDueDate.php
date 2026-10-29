<?php

namespace App\Actions\Cobranca\Listing;

use App\Models\FinCharges;
use App\Models\FinClientsBoleto;
use Carbon\CarbonImmutable;

/** Port de FinCharges::getPrazoFinal / getDataVencimento / resolverPrazoDiasCliente (legado). */
class ChargeDueDate
{
    public const EMPTY = 'N/D';

    /** Vencimento efetivo considerando o prazo negociado do cliente. */
    public function final(FinCharges $charge): ?CarbonImmutable
    {
        $due = $this->day($charge->due_date);
        $created = $this->day($charge->created_at);
        $days = $charge->embarque ? $this->clientTermDays($charge) : null;

        if ($days === null || $days <= 0) {
            return $due;
        }

        // due_date já gravado com prazo ou editado manualmente: respeita
        if ($due && $created && ! $due->equalTo($created)) {
            return $due;
        }

        return ($created ?? $due)?->addDays(max(0, $days - 1)); // o dia-base conta como dia 1 do prazo
    }

    /** Data a exibir na tela da cobrança: a real, mesmo vencida (só PDFs/notas usam "Na apresentação"). */
    public function display(FinCharges $charge): CarbonImmutable|string
    {
        return $this->final($charge) ?? self::EMPTY;
    }

    private function clientTermDays(FinCharges $charge): ?int
    {
        $term = FinClientsBoleto::where('ref_client', $charge->embarque->cliente_id)->first();

        if (! $term) {
            return null;
        }

        $specific = match ($charge->embarque->impor_expor) {
            'i' => $term->prazo_imp,
            'e' => $term->prazo_exp,
            default => null,
        };

        return $this->parseDays($specific) ?? $this->parseDays($term->prazo);
    }

    private function parseDays(mixed $value): ?int
    {
        $value = trim((string) $value);

        return match (true) {
            $value === '' => null,
            is_numeric($value) => (int) $value,
            default => preg_match('/-?\d+/', $value, $m) ? (int) $m[0] : null,
        };
    }

    private function day(mixed $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value)->startOfDay() : null;
    }
}
