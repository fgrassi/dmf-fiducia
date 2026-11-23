<?php

namespace App\Filament\Support;

use Illuminate\Support\Carbon;

/** Os widgets do dashboard só consideram reservas e cobranças cadastradas a partir desta data. */
class DashboardPeriod
{
    public const SINCE = '2026-01-01';

    public static function year(): int
    {
        return Carbon::parse(self::SINCE)->year;
    }

    /** A data de corte como aparece nas telas: 01/01/2026. */
    public static function sinceLabel(): string
    {
        return Carbon::parse(self::SINCE)->format('d/m/Y');
    }
}
