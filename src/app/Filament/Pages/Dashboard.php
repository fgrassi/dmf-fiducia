<?php

namespace App\Filament\Pages;

use App\Filament\Support\DashboardPeriod;
use Filament\Pages\Dashboard as BaseDashboard;

/** Dashboard do painel: o título leva o ano do corte e o subtítulo a data de corte e a data de hoje. */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return 'Dashboard '.DashboardPeriod::year();
    }

    public function getSubheading(): string
    {
        return 'Dados cadastrados desde '.DashboardPeriod::sinceLabel().' · hoje, '.today()->format('d/m/Y');
    }
}
