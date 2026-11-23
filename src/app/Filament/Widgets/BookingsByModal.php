<?php

namespace App\Filament\Widgets;

use App\Filament\Support\DashboardPeriod;
use App\Models\Embarque;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** Reservas cadastradas no mês por modal (marítimo, aéreo, seguro...). */
class BookingsByModal extends ChartWidget
{
    protected static ?int $sort = 21;

    protected ?string $heading = 'Reservas do mês por modal';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    public function getDescription(): string
    {
        $from = Carbon::parse(max(today()->startOfMonth()->toDateString(), DashboardPeriod::SINCE));

        return 'De '.$from->format('d/m/Y').' a '.today()->format('d/m/Y').', sem canceladas';
    }

    protected function getData(): array
    {
        $counts = Embarque::query()
            ->ofUserOperation()
            ->where('dteCadastro', '>=', max(today()->startOfMonth()->toDateString(), DashboardPeriod::SINCE))
            ->where('finalizado', '<>', 'c')
            ->pluck('tipo_embarque')
            ->countBy()
            ->sortDesc();

        return [
            'datasets' => [['data' => $counts->values()->all(), 'backgroundColor' => ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#94a3b8']]],
            'labels' => $counts->keys()->map(fn ($type) => Embarque::TYPES[$type] ?? ($type ?: 'Sem modal'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
