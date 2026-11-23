<?php

namespace App\Filament\Widgets;

/** Próximas saídas (saida_prevista) das reservas abertas. */
class NextDepartures extends UpcomingBookings
{
    protected static ?int $sort = 40;

    protected function dateColumn(): string
    {
        return 'saida_prevista';
    }

    protected function heading(): string
    {
        return 'Próximas saídas';
    }
}
