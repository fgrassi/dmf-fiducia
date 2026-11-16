<?php

namespace App\Filament\Pages;

use App\Filament\Support\ConfirmationsPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** relConfirmacaoChegada (legado), "Confirmações de Chegada". */
class ArrivalConfirmations extends ConfirmationsPage
{
    protected static string $kind = 'arrival';

    protected static ?string $title = 'Confirmações de Chegada';

    protected static ?string $navigationLabel = 'Confirmações de Chegada';

    protected static ?string $slug = 'relatorios/confirmacoes-chegada';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 60;
}
