<?php

namespace App\Enums;

use App\Enums\Concerns\StoresNoticeSetting;

/** Rotinas agendadas que geram dados (não mandam aviso): a tela Avisos por e-mail liga/desliga e troca o horário das diárias. */
enum Routine: string
{
    use StoresNoticeSetting;

    case GenerateCharges = 'cobranca:gerar';
    case GeneratePaymentOrders = 'ordens-pagamento:gerar';
    case GenerateOtherPaymentOrders = 'ordens-pagamento-outros:gerar';
    case GenerateCoverSummaries = 'capas:resumo-gerar';

    public function label(): string
    {
        return match ($this) {
            self::GenerateCharges => 'Gera as Instruções de Cobrança das reservas com despesas pendentes',
            self::GeneratePaymentOrders => 'Gera as ordens de pagamento ao armador',
            self::GenerateOtherPaymentOrders => 'Gera as ordens de pagamento a despachante, cia aérea e seguro',
            self::GenerateCoverSummaries => 'Gera o resumo de resultado das capas de contabilidade arquivadas',
        };
    }

    public function days(): string
    {
        $days = self::weekdaysLabel($this->weekdays());

        return match (true) {
            $this->defaultTime() !== null => $days,
            $days === 'Diário' => 'A cada hora',
            default => "A cada hora · {$days}",
        };
    }

    protected function defaultWeekdays(): array
    {
        return [0, 1, 2, 3, 4, 5, 6];
    }

    protected function defaultTime(): ?string
    {
        return $this === self::GenerateCoverSummaries ? '02:00' : null;
    }
}
