<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\DashboardPeriod;
use App\Models\Embarque;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Números da operação: reservas abertas, o que sai e chega nos próximos 7 dias e o ritmo de cadastro. */
class OperationStats extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Operação';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    protected function getDescription(): ?string
    {
        return 'Reservas cadastradas desde '.DashboardPeriod::sinceLabel().' · próximos 7 dias: '.today()->format('d/m').' a '.today()->addDays(7)->format('d/m/Y');
    }

    protected function getStats(): array
    {
        $today = today()->toDateString();
        $week = today()->addDays(7)->toDateString();
        $bookings = fn () => Embarque::query()->ofUserOperation()->where('dteCadastro', '>=', DashboardPeriod::SINCE);
        $open = fn () => $bookings()->where('finalizado', 'n');

        $perDay = $bookings()
            ->where('dteCadastro', '>=', max(today()->subDays(13)->toDateString(), DashboardPeriod::SINCE))
            ->where('finalizado', '<>', 'c')
            ->pluck('dteCadastro')
            ->countBy(fn (string $date) => substr($date, 0, 10));
        $trend = collect(range(13, 0))->map(fn (int $ago) => $perDay->get(today()->subDays($ago)->toDateString(), 0))->all();

        return [
            Stat::make('Reservas abertas', number_format($open()->count(), 0, ',', '.'))
                ->url($this->listUrl(['finalizado' => ['values' => ['n']]]))
                ->description(collect(['i' => 'importação', 'e' => 'exportação'])->only(auth()->user()->onlyOperation() ?? ['i', 'e'])->map(fn (string $label, string $operation) => $open()->where('impor_expor', $operation)->count().' '.$label)->implode(' · '))
                ->icon(Heroicon::OutlinedCube)
                ->color('primary'),
            Stat::make('Cadastradas hoje', (string) $perDay->get($today, 0))
                ->url($this->listUrl(['dteCadastro' => ['from' => $today, 'until' => $today]]))
                ->description(array_sum($trend).' nos últimos 14 dias')
                ->chart($trend)
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('info'),
            Stat::make('Saídas em 7 dias', (string) $open()->whereBetween('saida_prevista', [$today, $week])->count())
                ->url($this->listUrl(['finalizado' => ['values' => ['n']], 'saida_prevista' => ['from' => $today, 'until' => $week]]))
                ->description($open()->where('saida_prevista', '<', $today)->where('saida_prevista', '>', '0000-00-00')->count().' com saída prevista já vencida')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('warning'),
            Stat::make('Chegadas em 7 dias', (string) $bookings()->whereIn('finalizado', ['n', 's'])->whereBetween('chegada_prevista', [$today, $week])->count())
                ->url($this->listUrl(['finalizado' => ['values' => ['n', 's']], 'chegada_prevista' => ['from' => $today, 'until' => $week]]))
                ->description($bookings()->where('finalizado', 's')->count().' já chegaram ao destino')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success'),
        ];
    }

    /**
     * Lista de Bookings já filtrada: o corte de 2026 do dashboard entra como filtro de cadastro, para o total da lista bater
     * com o do cartão.
     *
     * @param  array<string, array<string, mixed>>  $filters
     */
    private function listUrl(array $filters): string
    {
        $filters += ['dteCadastro' => ['from' => DashboardPeriod::SINCE]];
        $filters['dteCadastro'] += ['from' => DashboardPeriod::SINCE];

        $tab = ['i' => 'impo', 'e' => 'expo'][auth()->user()->onlyOperation()] ?? null;

        return EmbarqueResource::getUrl('index', array_filter(['tab' => $tab, 'filters' => $filters]));
    }
}
