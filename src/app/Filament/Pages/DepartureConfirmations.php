<?php

namespace App\Filament\Pages;

use App\Filament\Support\ConfirmationsPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** relConfirmacaoEmbarque (legado), "Confirmações de Embarque". */
class DepartureConfirmations extends ConfirmationsPage
{
    protected static string $kind = 'departure';

    protected static ?string $title = 'Confirmações de Embarque';

    protected static ?string $navigationLabel = 'Confirmações de Embarque';

    protected static ?string $slug = 'relatorios/confirmacoes-embarque';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 61;
}
