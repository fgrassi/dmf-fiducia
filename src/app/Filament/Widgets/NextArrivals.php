<?php

namespace App\Filament\Widgets;

/** Próximas chegadas (chegada_prevista) das reservas abertas e das que já saíram. */
class NextArrivals extends UpcomingBookings
{
    protected static ?int $sort = 41;

    protected function dateColumn(): string
    {
        return 'chegada_prevista';
    }

    protected function statuses(): array
    {
        return ['n', 's'];
    }

    protected function heading(): string
    {
        return 'Próximas chegadas';
    }
}
