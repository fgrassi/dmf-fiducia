<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Charges\ChargeResource;
use App\Filament\Support\DashboardPeriod;
use App\Models\FinCharges;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Cobranças (ICs) a receber, vencidas e recebidas no mês. */
class FinanceStats extends StatsOverviewWidget
{
    protected static ?int $sort = 30;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    protected function getDescription(): ?string
    {
        return 'Cobranças criadas desde '.DashboardPeriod::sinceLabel().' · vencidas: antes de '.today()->format('d/m/Y').' · recebido no mês: desde '.today()->startOfMonth()->format('d/m/Y');
    }

    protected function getStats(): array
    {
        $today = today()->toDateString();
        $balance = '(COALESCE(total_value, 0) - COALESCE(discount, 0) - COALESCE(received_value, 0))';
        $open = fn (): Builder => FinCharges::query()->where('created_at', '>=', DashboardPeriod::SINCE)->where('status', 0);
        $overdue = fn (): Builder => $open()->where('due_date', '<', $today)->where('due_date', '>', '0000-00-00');
        $received = FinCharges::query()->where('created_at', '>=', DashboardPeriod::SINCE)->whereIn('status', [1, 2, 3])->where('payment_date', '>=', today()->startOfMonth()->toDateString());

        return [
            Stat::make('A receber', $this->brl($open()->sum(DB::raw($balance))))
                ->url(self::chargesUrl('abertos'))
                ->description($open()->count().' cobranças em aberto')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('primary'),
            Stat::make('Vencidas', $this->brl($overdue()->sum(DB::raw($balance))))
                ->url(self::overdueUrl())
                ->description($overdue()->count().' cobranças em atraso')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
            Stat::make('Recebido no mês', $this->brl((clone $received)->sum('received_value')))
                ->url(self::chargesUrl('pagos', ['data_ini' => today()->startOfMonth()->toDateString()]))
                ->description((clone $received)->count().' cobranças baixadas')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success'),
        ];
    }

    /**
     * Lista de Cobranças na aba e filtros dos cartões, com o corte de 2026 do dashboard como filtro de criação para o total
     * da lista bater com o do cartão.
     *
     * @param  array<string, string>  $filters
     */
    public static function chargesUrl(string $tab, array $filters = []): string
    {
        return ChargeResource::getUrl('index', ['tab' => $tab, 'filters' => ['busca' => $filters + ['criacao_ini' => DashboardPeriod::SINCE]]]);
    }

    /** Em aberto e vencidas antes de hoje (o vencimento 0000-00-00 do legado não conta como vencido). */
    public static function overdueUrl(): string
    {
        return self::chargesUrl('abertos', ['venc_ini' => '0001-01-01', 'venc_fim' => today()->subDay()->toDateString()]);
    }

    private function brl(float|int|string|null $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }
}
