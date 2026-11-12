<?php

namespace App\Filament\Support;

use App\Enums\OperationType;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;

/** Coluna/entrada de operação (Impo azul, Expo verde) para reaproveitar nas telas. */
class OperationBadge
{
    public static function column(string $name = 'impor_expor'): TextColumn
    {
        return TextColumn::make($name)
            ->badge()
            ->formatStateUsing(fn (?string $state) => OperationType::labelFor($state))
            ->color(fn (?string $state) => OperationType::tryFrom((string) $state)?->getColor());
    }

    public static function entry(string $name = 'impor_expor'): TextEntry
    {
        return TextEntry::make($name)
            ->badge()
            ->formatStateUsing(fn (?string $state) => OperationType::labelFor($state))
            ->color(fn (?string $state) => OperationType::tryFrom((string) $state)?->getColor());
    }
}
