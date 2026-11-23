<?php

namespace App\Filament\Widgets;

use App\Filament\Support\DashboardPeriod;
use App\Models\Embarque;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** Reservas cadastradas nos últimos 12 meses, importação e exportação lado a lado. */
class BookingsPerMonth extends ChartWidget
{
    protected static ?int $sort = 20;

    protected ?string $heading = 'Reservas por mês';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    public function getDescription(): string
    {
        $from = max(today()->startOfMonth()->subMonthsNoOverflow(11), Carbon::parse(DashboardPeriod::SINCE)->startOfMonth());

        return 'De '.$from->format('m/Y').' a '.today()->format('m/Y').' (até 12 meses), sem canceladas';
    }

    protected function getData(): array
    {
        $months = collect(range(11, 0))->map(fn (int $ago) => today()->startOfMonth()->subMonthsNoOverflow($ago))
            ->filter(fn ($month) => $month->toDateString() >= DashboardPeriod::SINCE)
            ->values();
        $counts = Embarque::query()
            ->ofUserOperation()
            ->where('dteCadastro', '>=', $months->first()->toDateString())
            ->where('finalizado', '<>', 'c')
            ->whereIn('impor_expor', ['i', 'e'])
            ->get(['dteCadastro', 'impor_expor'])
            ->countBy(fn (Embarque $booking) => $booking->impor_expor.substr($booking->dteCadastro, 0, 7));
        $series = fn (string $operation) => $months->map(fn ($month) => $counts->get($operation.$month->format('Y-m'), 0))->all();

        return [
            'datasets' => collect([
                'i' => ['label' => 'Importação', 'data' => $series('i'), 'backgroundColor' => '#3b82f6'],
                'e' => ['label' => 'Exportação', 'data' => $series('e'), 'backgroundColor' => '#22c55e'],
            ])->only(auth()->user()->onlyOperation() ?? ['i', 'e'])->values()->all(),
            'labels' => $months->map(fn ($month) => $month->translatedFormat('M/y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
