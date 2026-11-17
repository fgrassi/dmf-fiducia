<?php

namespace App\Filament\Pages;

use App\Filament\Support\TrackingControlPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use UnitEnum;

/** controleTracking/loadImpo (legado), "Controle de Tracking - IMPO". */
class ImportTrackingControl extends TrackingControlPage
{
    protected static string $operation = 'i';

    protected static ?string $title = 'Controle de Tracking - Importação';

    protected static ?string $navigationLabel = 'Tracking - Importação';

    protected static ?string $slug = 'reservas/tracking-importacao';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Reserva';

    protected static ?int $navigationSort = 30;

    protected static function extraColumn(): TextColumn
    {
        return TextColumn::make('free_time')
            ->label('Free Time')
            ->sortable();
    }
}
