<?php

namespace App\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use UnitEnum;

/** Navios (legado): as abas Navios, Programação de Navios e Capas de Navio (Impo) dos cadastros. */
class Ships extends Cluster
{
    protected static ?string $navigationLabel = 'Navios';

    protected static ?string $slug = 'cadastros/navios';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 100;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
