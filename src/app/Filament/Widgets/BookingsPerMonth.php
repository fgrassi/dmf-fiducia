<?php

namespace App\Filament\Widgets;

use App\Filament\Support\DashboardPeriod;
use App\Models\Embarque;
use Filament\Widgets\ChartWidget;

/** Reservas cadastradas nos últimos 12 meses, importação e exportação lado a lado. */
class BookingsPerMonth extends ChartWidget
{
    protected static ?int $sort = 20;

    protected ?string $heading = 'Reservas por mês';

    protected ?string $description = 'Até 12 meses a partir de 2026, sem canceladas';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    protected function getData(): array
    {
        $months = collect(range(11, 0))->map(fn (int $ago) => today()->startOfMonth()->subMonthsNoOverflow($ago))
            ->filter(fn ($month) => $month->toDateString() >= DashboardPeriod::SINCE)
            ->values();
        $counts = Embarque::query()
            ->where('dteCadastro', '>=', $months->first()->toDateString())
            ->where('finalizado', '<>', 'c')
            ->whereIn('impor_expor', ['i', 'e'])
            ->get(['dteCadastro', 'impor_expor'])
            ->countBy(fn (Embarque $booking) => $booking->impor_expor.substr($booking->dteCadastro, 0, 7));
        $series = fn (string $operation) => $months->map(fn ($month) => $counts->get($operation.$month->format('Y-m'), 0))->all();

        return [
            'datasets' => [
                ['label' => 'Importação', 'data' => $series('i'), 'backgroundColor' => '#3b82f6'],
                ['label' => 'Exportação', 'data' => $series('e'), 'backgroundColor' => '#22c55e'],
            ],
            'labels' => $months->map(fn ($month) => $month->translatedFormat('M/y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
