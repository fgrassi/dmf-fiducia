<?php

namespace App\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use UnitEnum;

/** Localidades (legado): as abas País, Estado e Cidade dos cadastros. */
class Locations extends Cluster
{
    protected static ?string $navigationLabel = 'Localidades';

    protected static ?string $slug = 'cadastros/localidades';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
