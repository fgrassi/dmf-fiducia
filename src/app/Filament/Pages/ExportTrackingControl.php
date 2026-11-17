<?php

namespace App\Filament\Pages;

use App\Filament\Support\TrackingControlPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use UnitEnum;

/** controleTracking/loadExpo (legado), "Controle de Tracking - EXPO". */
class ExportTrackingControl extends TrackingControlPage
{
    protected static string $operation = 'e';

    protected static ?string $title = 'Controle de Tracking - Exportação';

    protected static ?string $navigationLabel = 'Tracking - Exportação';

    protected static ?string $slug = 'reservas/tracking-exportacao';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Reserva';

    protected static ?int $navigationSort = 31;

    protected static function extraColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label('Status');
    }
}
